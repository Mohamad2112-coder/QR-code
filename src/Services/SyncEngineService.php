<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\UUID;
use Exception;

class SyncEngineService
{
    private const STATUS_RANK = [
        'pending' => 1,
        'preparing' => 2,
        'ready' => 3,
        'collected' => 4,
        'cancelled' => 99
    ];

    /**
     * Authorize a local edge device (Local Business Hub, Cashier POS, KDS terminal).
     */
    public static function authorizeDevice(string $businessId, string $deviceUuid, string $deviceSecret): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM local_devices WHERE business_id = ? AND device_uuid = ? AND is_authorized = 1");
        $stmt->execute([$businessId, $deviceUuid]);
        $device = $stmt->fetch();

        if ($device && password_verify($deviceSecret, $device['auth_token_hash'])) {
            $db->prepare("UPDATE local_devices SET last_heartbeat_at = NOW(), last_ip_address = ? WHERE id = ?")
                ->execute([$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $device['id']]);
            return $device;
        }

        return null;
    }

    /**
     * Ingest and process an offline synchronization batch.
     */
    public static function processSyncBatch(
        string $businessId,
        string $deviceUuid,
        string $batchId,
        array $offlineOrders = [],
        array $statusUpdates = []
    ): array {
        $db = Database::getConnection();

        // Check if this batch was already completed
        $stmtCheck = $db->prepare("SELECT * FROM sync_batches WHERE batch_id = ?");
        $stmtCheck->execute([$batchId]);
        $existingBatch = $stmtCheck->fetch();

        if ($existingBatch && $existingBatch['status'] === 'completed') {
            return [
                'batch_id' => $batchId,
                'status' => 'already_completed',
                'orders_processed' => $existingBatch['orders_count'],
                'updates_processed' => $existingBatch['status_updates_count']
            ];
        }

        $syncBatchDbId = UUID::v4();
        $ordersCount = 0;
        $updatesCount = 0;
        $conflictsCount = 0;
        $eventLogs = [];

        $db->beginTransaction();
        try {
            // 1. Process Offline Orders
            foreach ($offlineOrders as $orderData) {
                $orderId = $orderData['id'] ?? UUID::v4();
                $idempotencyKey = $orderData['idempotency_key'] ?? "offline_ord_{$orderId}";

                // Check if already in database
                $stmtOrderCheck = $db->prepare("SELECT id, status FROM orders WHERE id = ? OR idempotency_key = ?");
                $stmtOrderCheck->execute([$orderId, $idempotencyKey]);
                $existingOrder = $stmtOrderCheck->fetch();

                if ($existingOrder) {
                    $eventLogs[] = [
                        'type' => 'order',
                        'id' => $orderId,
                        'resolution' => 'skipped_duplicate',
                        'msg' => "Order {$orderId} already present in cloud."
                    ];
                    continue;
                }

                // Check or resolve token uniqueness
                $token = $orderData['tracking_token'] ?? OrderService::generateTrackingToken();
                $stmtTokenCheck = $db->prepare("SELECT id FROM orders WHERE tracking_token = ?");
                $stmtTokenCheck->execute([$token]);
                if ($stmtTokenCheck->fetchColumn()) {
                    // Extremely rare collision or re-sync with existing token
                    $token = OrderService::generateTrackingToken();
                }

                $total = 0.00;
                $items = $orderData['items'] ?? [];
                foreach ($items as $item) {
                    $total += ((float)$item['price']) * max(1, (int)$item['qty']);
                }

                $orderNumber = $orderData['order_number'] ?? 'OFF-' . substr(bin2hex(random_bytes(4)), 0, 6);
                $createdAt = $orderData['created_at'] ?? date('Y-m-d H:i:s');

                // Insert into cloud
                $stmtInsert = $db->prepare("
                    INSERT INTO orders (
                        id, business_id, order_number, tracking_token, status, total_amount,
                        customer_notes, staff_notes, created_by_user_id, is_offline_created,
                        sync_state, local_device_id, idempotency_key, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, 1, 'synced', ?, ?, ?, NOW())
                ");
                $stmtInsert->execute([
                    $orderId,
                    $businessId,
                    $orderNumber,
                    $token,
                    $total,
                    $orderData['customer_notes'] ?? null,
                    $orderData['staff_notes'] ?? 'Created via Local Hub Offline',
                    $orderData['created_by_user_id'] ?? null,
                    $deviceUuid,
                    $idempotencyKey,
                    $createdAt
                ]);

                // Insert items
                $stmtItemInsert = $db->prepare("
                    INSERT INTO order_items (id, order_id, product_id, product_name_snapshot, unit_price_snapshot, quantity, item_notes, subtotal, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($items as $it) {
                    $itemPrice = (float)$it['price'];
                    $itemQty = max(1, (int)$it['qty']);
                    $itemSubtotal = round($itemPrice * $itemQty, 2);

                    $stmtItemInsert->execute([
                        UUID::v4(),
                        $orderId,
                        $it['product_id'] ?? null,
                        $it['name'] ?? 'Item',
                        $itemPrice,
                        $itemQty,
                        $it['notes'] ?? null,
                        $itemSubtotal,
                        $createdAt
                    ]);
                }

                // Initial status history
                $db->prepare("
                    INSERT INTO order_status_history (id, order_id, previous_status, new_status, changed_by_user_id, source, device_info, is_offline_transition, transition_time, created_at)
                    VALUES (?, ?, NULL, 'pending', ?, 'local_hub', ?, 1, ?, NOW())
                ")->execute([UUID::v4(), $orderId, $orderData['created_by_user_id'] ?? null, $deviceUuid, $createdAt]);

                $ordersCount++;
                $eventLogs[] = [
                    'type' => 'order',
                    'id' => $orderId,
                    'resolution' => 'applied',
                    'msg' => "Offline order {$orderNumber} synchronized to cloud successfully."
                ];
            }

            // 2. Process Offline Status Transitions
            foreach ($statusUpdates as $update) {
                $orderId = $update['order_id'];
                $newStatus = $update['new_status'];
                $transitionTime = $update['transition_time'] ?? date('Y-m-d H:i:s');
                $changedBy = $update['user_id'] ?? null;

                $stmtOrd = $db->prepare("SELECT id, status, order_number FROM orders WHERE id = ? FOR UPDATE");
                $stmtOrd->execute([$orderId]);
                $cloudOrder = $stmtOrd->fetch();

                if (!$cloudOrder) {
                    $eventLogs[] = [
                        'type' => 'status_transition',
                        'id' => $orderId,
                        'resolution' => 'error',
                        'msg' => "Cannot apply status {$newStatus}: Order {$orderId} does not exist in cloud."
                    ];
                    continue;
                }

                $cloudRank = self::STATUS_RANK[$cloudOrder['status']] ?? 0;
                $offlineRank = self::STATUS_RANK[$newStatus] ?? 0;

                // State Conflict Resolution Rules
                if ($cloudRank > $offlineRank) {
                    // Cloud has already progressed further (e.g. collected vs ready), cloud wins
                    $conflictsCount++;
                    $eventLogs[] = [
                        'type' => 'status_transition',
                        'id' => $orderId,
                        'resolution' => 'conflict_cloud_wins',
                        'msg' => "Cloud status ({$cloudOrder['status']}) ranks higher than offline status ({$newStatus}). Cloud state preserved."
                    ];
                    continue;
                }

                if ($cloudOrder['status'] === $newStatus) {
                    $eventLogs[] = [
                        'type' => 'status_transition',
                        'id' => $orderId,
                        'resolution' => 'skipped_duplicate',
                        'msg' => "Status is already {$newStatus}."
                    ];
                    continue;
                }

                // Apply transition
                OrderService::updateStatus($orderId, $newStatus, $changedBy, 'local_hub', $deviceUuid, true);
                $updatesCount++;
                $eventLogs[] = [
                    'type' => 'status_transition',
                    'id' => $orderId,
                    'resolution' => 'applied',
                    'msg' => "Order {$cloudOrder['order_number']} status advanced to {$newStatus} from offline sync."
                ];
            }

            // Record Sync Batch
            $stmtBatch = $db->prepare("
                INSERT INTO sync_batches (id, business_id, device_uuid, batch_id, orders_count, status_updates_count, conflicts_count, status, details_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW())
            ");
            $stmtBatch->execute([
                $syncBatchDbId,
                $businessId,
                $deviceUuid,
                $batchId,
                $ordersCount,
                $updatesCount,
                $conflictsCount,
                json_encode($eventLogs, JSON_UNESCAPED_UNICODE)
            ]);

            // Record individual sync events
            $stmtEv = $db->prepare("
                INSERT INTO sync_events (id, sync_batch_id, business_id, entity_type, entity_id, action, resolution, message, created_at)
                VALUES (?, ?, ?, ?, ?, 'sync', ?, ?, NOW())
            ");
            foreach ($eventLogs as $ev) {
                $stmtEv->execute([
                    UUID::v4(),
                    $syncBatchDbId,
                    $businessId,
                    $ev['type'],
                    $ev['id'],
                    $ev['resolution'],
                    $ev['msg']
                ]);
            }

            $db->commit();

            AuditService::log('sync_batch_processed', "Sync batch {$batchId} processed: {$ordersCount} orders, {$updatesCount} status updates, {$conflictsCount} conflicts", $businessId);

            return [
                'batch_id' => $batchId,
                'status' => 'completed',
                'orders_processed' => $ordersCount,
                'updates_processed' => $updatesCount,
                'conflicts' => $conflictsCount,
                'events' => $eventLogs
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
