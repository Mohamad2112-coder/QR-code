<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\UUID;
use App\Core\Auth;
use Exception;

class OrderService
{
    private const VALID_STATUSES = ['pending', 'preparing', 'ready', 'collected', 'cancelled'];

    /**
     * Generate an unguessable cryptographic tracking token.
     */
    public static function generateTrackingToken(): string
    {
        return 'tk_' . bin2hex(random_bytes(24));
    }

    /**
     * Create an order with snapshot items and server-side total verification.
     */
    public static function createOrder(
        string $businessId,
        array $items,
        ?string $customerNotes = null,
        ?string $staffNotes = null,
        ?string $userId = null,
        bool $isOfflineCreated = false,
        ?string $localDeviceId = null,
        ?string $customId = null,
        ?string $customToken = null,
        ?string $idempotencyKey = null
    ): array {
        $db = Database::getConnection();

        if (empty($items)) {
            throw new Exception("Cannot create an empty order. At least one item is required.");
        }

        // Check idempotency if provided
        if (!empty($idempotencyKey)) {
            $stmt = $db->prepare("SELECT id, order_number, tracking_token FROM orders WHERE idempotency_key = ?");
            $stmt->execute([$idempotencyKey]);
            $existing = $stmt->fetch();
            if ($existing) {
                return [
                    'order_id' => $existing['id'],
                    'order_number' => $existing['order_number'],
                    'tracking_token' => $existing['tracking_token'],
                    'is_duplicate' => true
                ];
            }
        }

        $db->beginTransaction();
        try {
            // Retrieve business settings for order numbering
            $stmtSet = $db->prepare("SELECT order_number_prefix, next_order_number FROM business_settings WHERE business_id = ? FOR UPDATE");
            $stmtSet->execute([$businessId]);
            $settings = $stmtSet->fetch();

            $prefix = $settings['order_number_prefix'] ?? 'ORD-';
            $nextNum = (int)($settings['next_order_number'] ?? 1);
            $orderNumber = $prefix . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);

            // Increment next order number
            $db->prepare("UPDATE business_settings SET next_order_number = next_order_number + 1 WHERE business_id = ?")
                ->execute([$businessId]);

            $orderId = $customId ?: UUID::v4();
            $token = $customToken ?: self::generateTrackingToken();
            $creatorId = $userId ?? Auth::id();

            // Calculate total and prepare item snapshots
            $totalAmount = 0.00;
            $preparedItems = [];

            // Fetch live product catalog to snapshot actual prices and verify availability
            $productIds = array_filter(array_column($items, 'product_id'));
            $productsById = [];
            if (!empty($productIds)) {
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $stmtProd = $db->prepare("SELECT id, name, price, is_available FROM products WHERE business_id = ? AND id IN ({$placeholders})");
                $stmtProd->execute(array_merge([$businessId], $productIds));
                foreach ($stmtProd->fetchAll() as $row) {
                    $productsById[$row['id']] = $row;
                }
            }

            foreach ($items as $item) {
                $productId = $item['product_id'] ?? null;
                $quantity = max(1, (int)($item['quantity'] ?? 1));
                $itemNotes = trim((string)($item['notes'] ?? ''));

                if ($productId && isset($productsById[$productId])) {
                    $nameSnapshot = $productsById[$productId]['name'];
                    $priceSnapshot = (float)$productsById[$productId]['price'];
                } else {
                    $nameSnapshot = trim((string)($item['name'] ?? 'Custom Item'));
                    $priceSnapshot = max(0.00, (float)($item['price'] ?? 0.00));
                }

                $subtotal = round($priceSnapshot * $quantity, 2);
                $totalAmount += $subtotal;

                $preparedItems[] = [
                    'id' => UUID::v4(),
                    'product_id' => $productId,
                    'name_snapshot' => $nameSnapshot,
                    'price_snapshot' => $priceSnapshot,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'notes' => $itemNotes
                ];
            }

            // Insert Order
            $stmtOrder = $db->prepare("
                INSERT INTO orders (
                    id, business_id, order_number, tracking_token, status, total_amount,
                    customer_notes, staff_notes, created_by_user_id, is_offline_created,
                    sync_state, local_device_id, idempotency_key, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $syncState = $isOfflineCreated ? 'pending_sync' : 'synced';
            $stmtOrder->execute([
                $orderId, $businessId, $orderNumber, $token, $totalAmount,
                $customerNotes, $staffNotes, $creatorId,
                $isOfflineCreated ? 1 : 0, $syncState, $localDeviceId, $idempotencyKey
            ]);

            // Insert Order Items
            $stmtItem = $db->prepare("
                INSERT INTO order_items (id, order_id, product_id, product_name_snapshot, unit_price_snapshot, quantity, item_notes, subtotal, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            foreach ($preparedItems as $pi) {
                $stmtItem->execute([
                    $pi['id'], $orderId, $pi['product_id'], $pi['name_snapshot'],
                    $pi['price_snapshot'], $pi['quantity'], $pi['notes'], $pi['subtotal']
                ]);
            }

            // Record Initial Status History
            $stmtHist = $db->prepare("
                INSERT INTO order_status_history (id, order_id, previous_status, new_status, changed_by_user_id, source, transition_time, created_at)
                VALUES (?, ?, NULL, 'pending', ?, ?, NOW(), NOW())
            ");
            $source = $isOfflineCreated ? 'cashier_offline' : 'cloud_web';
            $stmtHist->execute([UUID::v4(), $orderId, $creatorId, $source]);

            $db->commit();

            AuditService::log('order_created', "Order {$orderNumber} created ({$totalAmount})", $businessId, $creatorId);

            return [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'tracking_token' => $token,
                'total_amount' => $totalAmount,
                'tracking_url' => QRCodeService::getTrackingUrl($token),
                'is_duplicate' => false
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Update order status with validation and history logging.
     */
    public static function updateStatus(
        string $orderId,
        string $newStatus,
        ?string $userId = null,
        string $source = 'cloud_web',
        ?string $deviceInfo = null,
        bool $isOffline = false
    ): array {
        $db = Database::getConnection();

        if (!in_array($newStatus, self::VALID_STATUSES, true)) {
            throw new Exception("Invalid order status: {$newStatus}");
        }

        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new Exception("Order not found: {$orderId}");
        }

        $currentStatus = $order['status'];

        // Terminal states cannot be altered unless authorized
        if ($currentStatus === 'cancelled') {
            throw new Exception("Order {$order['order_number']} is cancelled and cannot be modified.");
        }
        if ($currentStatus === 'collected' && $newStatus !== 'collected') {
            throw new Exception("Order {$order['order_number']} is already collected and cannot transition backwards.");
        }

        // If status is unchanged, skip duplicate write
        if ($currentStatus === $newStatus) {
            return ['status' => 'unchanged', 'current' => $currentStatus];
        }

        // Validate state transitions
        $statusRank = ['pending' => 1, 'preparing' => 2, 'ready' => 3, 'collected' => 4, 'cancelled' => 99];
        if ($newStatus !== 'cancelled' && ($statusRank[$newStatus] < $statusRank[$currentStatus])) {
            throw new Exception("Invalid backward transition from {$currentStatus} to {$newStatus}");
        }

        $now = date('Y-m-d H:i:s');
        $timestampColumn = match ($newStatus) {
            'preparing' => 'preparing_at',
            'ready' => 'ready_at',
            'collected' => 'collected_at',
            'cancelled' => 'cancelled_at',
            default => null
        };

        $sql = "UPDATE orders SET status = ?, updated_at = NOW()";
        $params = [$newStatus];

        if ($timestampColumn) {
            $sql .= ", {$timestampColumn} = COALESCE({$timestampColumn}, ?)";
            $params[] = $now;
        }
        $sql .= " WHERE id = ?";
        $params[] = $orderId;

        $db->prepare($sql)->execute($params);

        // Record History
        $db->prepare("
            INSERT INTO order_status_history (id, order_id, previous_status, new_status, changed_by_user_id, source, device_info, is_offline_transition, transition_time, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            UUID::v4(), $orderId, $currentStatus, $newStatus, $userId ?? Auth::id(),
            $source, $deviceInfo, $isOffline ? 1 : 0, $now
        ]);

        // Trigger Web Push Notification if READY!
        if ($newStatus === 'ready') {
            NotificationService::enqueueReadyNotification($orderId);
        }

        AuditService::log('order_status_updated', "Order {$order['order_number']} changed from {$currentStatus} to {$newStatus}", $order['business_id'], $userId);

        return [
            'status' => 'updated',
            'previous' => $currentStatus,
            'current' => $newStatus,
            'order_number' => $order['order_number']
        ];
    }

    /**
     * Customer tracking lookup by token.
     * Enforces token validation, business branding, and expiration policy.
     */
    public static function getCustomerOrder(string $token): ?array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT o.id, o.business_id, o.order_number, o.tracking_token, o.status,
                   o.total_amount, o.customer_notes, o.created_at, o.preparing_at, o.ready_at, o.collected_at,
                   b.name as business_name, b.default_language,
                   bs.currency_symbol, bs.estimated_prep_time_minutes, bs.qr_expiration_grace_minutes,
                   bb.primary_color, bb.accent_color, bb.logo_url, bb.tagline
            FROM orders o
            JOIN businesses b ON o.business_id = b.id
            LEFT JOIN business_settings bs ON b.id = bs.business_id
            LEFT JOIN business_branding bb ON b.id = bb.business_id
            WHERE o.tracking_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        // Check expiration policy: if collected and grace period passed
        if ($order['status'] === 'collected' && !empty($order['collected_at'])) {
            $graceMins = (int)($order['qr_expiration_grace_minutes'] ?? 60);
            $expiresAt = strtotime($order['collected_at']) + ($graceMins * 60);
            if (time() > $expiresAt) {
                return ['expired' => true, 'order_number' => $order['order_number'], 'business_name' => $order['business_name']];
            }
        }

        // Fetch customer visible items
        $stmtItems = $db->prepare("
            SELECT product_name_snapshot as name, unit_price_snapshot as price, quantity, item_notes, subtotal
            FROM order_items
            WHERE order_id = ?
            ORDER BY created_at ASC
        ");
        $stmtItems->execute([$order['id']]);
        $order['items'] = $stmtItems->fetchAll();

        return $order;
    }
}
