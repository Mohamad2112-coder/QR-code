<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\UUID;
use App\Core\Config;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class NotificationService
{
    /**
     * Subscribe customer device to order updates.
     */
    public static function subscribe(
        string $businessId,
        string $orderId,
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $userAgent = null
    ): array {
        $db = Database::getConnection();

        // Check if identical subscription already exists
        $stmt = $db->prepare("SELECT id FROM push_subscriptions WHERE order_id = ? AND endpoint = ?");
        $stmt->execute([$orderId, $endpoint]);
        $existing = $stmt->fetch();

        if ($existing) {
            $db->prepare("UPDATE push_subscriptions SET p256dh_key = ?, auth_token = ?, is_active = 1 WHERE id = ?")
                ->execute([$p256dh, $auth, $existing['id']]);
            return ['status' => 'updated', 'id' => $existing['id']];
        }

        $id = UUID::v4();
        $stmt = $db->prepare("
            INSERT INTO push_subscriptions (id, business_id, order_id, endpoint, p256dh_key, auth_token, user_agent, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$id, $businessId, $orderId, $endpoint, $p256dh, $auth, substr((string)$userAgent, 0, 255)]);

        AuditService::log('push_subscription_registered', "Customer registered push notification for order ID {$orderId}", $businessId);

        return ['status' => 'created', 'id' => $id];
    }

    /**
     * Enqueue a Ready notification job for an order.
     * Enforces idempotency key so that repeated calls/syncs NEVER send duplicate notifications.
     */
    public static function enqueueReadyNotification(string $orderId): int
    {
        $db = Database::getConnection();

        // Get order details
        $stmt = $db->prepare("
            SELECT o.*, b.name as business_name, bs.notification_ready_message_en, bs.currency_symbol
            FROM orders o
            JOIN businesses b ON o.business_id = b.id
            JOIN business_settings bs ON b.id = bs.business_id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            return 0;
        }

        // Find active subscriptions for this order
        $stmtSub = $db->prepare("SELECT * FROM push_subscriptions WHERE order_id = ? AND is_active = 1");
        $stmtSub->execute([$orderId]);
        $subscriptions = $stmtSub->fetchAll();

        if (empty($subscriptions)) {
            return 0;
        }

        $enqueued = 0;
        $title = "🔔 Order Ready - {$order['business_name']}";
        $body = "Your order #{$order['order_number']} is ready for pickup!";
        if (!empty($order['notification_ready_message_en'])) {
            $body = str_replace('{order_number}', (string)$order['order_number'], $order['notification_ready_message_en']);
        }

        $payload = [
            'title' => $title,
            'body' => $body,
            'icon' => '/assets/icons/icon-192.png',
            'badge' => '/assets/icons/badge-72.png',
            'url' => QRCodeService::getTrackingUrl($order['tracking_token']),
            'data' => [
                'order_id' => $order['id'],
                'order_number' => $order['order_number'],
                'status' => 'ready',
                'timestamp' => time()
            ]
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            $idempotencyKey = "notif_ready_{$orderId}_{$sub['id']}";

            // Check if job already enqueued/sent
            $stmtCheck = $db->prepare("SELECT id FROM notification_jobs WHERE idempotency_key = ?");
            $stmtCheck->execute([$idempotencyKey]);
            if ($stmtCheck->fetchColumn()) {
                continue; // Skip duplicate notification!
            }

            $jobId = UUID::v4();
            $stmtJob = $db->prepare("
                INSERT INTO notification_jobs (id, business_id, order_id, subscription_id, idempotency_key, status, attempts, payload_json, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', 0, ?, NOW())
            ");
            $stmtJob->execute([$jobId, $order['business_id'], $orderId, $sub['id'], $idempotencyKey, $payloadJson]);
            $enqueued++;
        }

        return $enqueued;
    }

    /**
     * Process pending notification jobs.
     */
    public static function processPendingJobs(int $limit = 20): array
    {
        $db = Database::getConnection();
        $vapidConfig = Config::load('vapid');

        $stmt = $db->prepare("
            SELECT nj.*, ps.endpoint, ps.p256dh_key, ps.auth_token 
            FROM notification_jobs nj
            JOIN push_subscriptions ps ON nj.subscription_id = ps.id
            WHERE nj.status = 'pending'
            ORDER BY nj.created_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll();

        if (empty($jobs)) {
            return ['processed' => 0, 'successful' => 0, 'failed' => 0];
        }

        $auth = [
            'VAPID' => [
                'subject' => $vapidConfig['subject'],
                'publicKey' => $vapidConfig['public_key'],
                'privateKey' => $vapidConfig['private_key'],
            ],
        ];

        $webPush = null;
        try {
            $webPush = new WebPush($auth);
        } catch (\Throwable $e) {
            error_log("WebPush init failed: " . $e->getMessage());
        }

        $successful = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            // Mark as processing
            $db->prepare("UPDATE notification_jobs SET status = 'processing', attempts = attempts + 1 WHERE id = ?")
                ->execute([$job['id']]);

            if (!$webPush) {
                $db->prepare("UPDATE notification_jobs SET status = 'failed', error_message = 'VAPID client init failed' WHERE id = ?")
                    ->execute([$job['id']]);
                $failed++;
                continue;
            }

            try {
                $subscription = Subscription::create([
                    'endpoint' => $job['endpoint'],
                    'publicKey' => $job['p256dh_key'],
                    'authToken' => $job['auth_token'],
                ]);

                $report = $webPush->sendOneNotification($subscription, $job['payload_json']);

                if ($report->isSuccess()) {
                    $db->prepare("UPDATE notification_jobs SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ?")
                        ->execute([$job['id']]);

                    $db->prepare("INSERT INTO notification_logs (id, business_id, order_id, status, status_code, response_body, created_at) VALUES (?, ?, ?, 'sent', 201, 'Delivered to push service', NOW())")
                        ->execute([UUID::v4(), $job['business_id'], $job['order_id']]);

                    $successful++;
                } else {
                    $reason = $report->getReason();
                    $isExpired = $report->isSubscriptionExpired();

                    $db->prepare("UPDATE notification_jobs SET status = 'failed', error_message = ? WHERE id = ?")
                        ->execute([$reason, $job['id']]);

                    // Deactivate expired or unsubscribed tokens (404/410)
                    if ($isExpired) {
                        $db->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?")
                            ->execute([$job['subscription_id']]);
                    }

                    $db->prepare("INSERT INTO notification_logs (id, business_id, order_id, status, status_code, response_body, created_at) VALUES (?, ?, ?, 'failed', 400, ?, NOW())")
                        ->execute([UUID::v4(), $job['business_id'], $job['order_id'], substr($reason, 0, 255)]);

                    $failed++;
                }
            } catch (\Throwable $e) {
                $db->prepare("UPDATE notification_jobs SET status = 'failed', error_message = ? WHERE id = ?")
                    ->execute([$e->getMessage(), $job['id']]);
                $failed++;
            }
        }

        return ['processed' => count($jobs), 'successful' => $successful, 'failed' => $failed];
    }
}
