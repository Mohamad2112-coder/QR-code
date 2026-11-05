<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/hub_database.php';

use LocalHub\HubDatabase;

$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    echo "Error: config.json not found in local_hub directory.\n";
    exit(1);
}

$config = json_decode((string)file_get_contents($configFile), true);
$cloudUrl = rtrim($config['cloud_url'], '/');
$businessId = $config['business_id'];
$deviceUuid = $config['device_uuid'];
$deviceSecret = $config['device_secret'];
$interval = (int)($config['sync_interval_seconds'] ?? 10);

echo "=========================================================\n";
echo "   Local Business Hub Background Sync Daemon             \n";
echo "   Target Cloud: {$cloudUrl}\n";
echo "   Business ID: {$businessId} | Device: {$deviceUuid}\n";
echo "   Polling Interval: {$interval}s\n";
echo "=========================================================\n\n";

$db = HubDatabase::getConnection();

while (true) {
    try {
        // 1. Check Cloud Connectivity & Sync Catalog
        $catalogEndpoint = "{$cloudUrl}/api/hub/catalog?business_id=" . urlencode($businessId) .
            "&device_uuid=" . urlencode($deviceUuid) .
            "&device_secret=" . urlencode($deviceSecret);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true
            ]
        ]);

        $responseRaw = @file_get_contents($catalogEndpoint, false, $ctx);

        if ($responseRaw === false) {
            echo "[" . date('H:i:s') . "] Cloud offline / unreachable. Local Hub operating autonomously.\n";
            sleep($interval);
            continue;
        }

        $catalogData = json_decode($responseRaw, true);
        if ($catalogData && isset($catalogData['products'])) {
            // Update local SQLite catalog cache
            $stmtCat = $db->prepare("INSERT OR REPLACE INTO local_catalog (id, name, price, is_available, updated_at) VALUES (?, ?, ?, ?, ?)");
            foreach ($catalogData['products'] as $p) {
                $stmtCat->execute([$p['id'], $p['name'], $p['price'], $p['is_available'], date('Y-m-d H:i:s')]);
            }
        }

        // 2. Find pending unsynced orders
        $stmtPendingOrders = $db->query("SELECT * FROM local_orders WHERE sync_state = 'pending_sync'");
        $pendingOrders = $stmtPendingOrders->fetchAll();

        // 3. Find pending status updates
        $stmtPendingUpdates = $db->query("SELECT * FROM local_status_updates WHERE sync_state = 'pending_sync'");
        $pendingUpdates = $stmtPendingUpdates->fetchAll();

        if (empty($pendingOrders) && empty($pendingUpdates)) {
            // Nothing to sync, all local records are up to date with cloud
            echo "[" . date('H:i:s') . "] Cloud connected. Local state synchronized. (0 pending)\n";
        } else {
            echo "[" . date('H:i:s') . "] Preparing sync batch: " . count($pendingOrders) . " orders, " . count($pendingUpdates) . " status updates...\n";

            // Attach items to pending orders
            $ordersPayload = [];
            foreach ($pendingOrders as $po) {
                $stmtItems = $db->prepare("SELECT product_id, name, price, quantity as qty, notes FROM local_order_items WHERE order_id = ?");
                $stmtItems->execute([$po['id']]);
                $items = $stmtItems->fetchAll();

                $ordersPayload[] = [
                    'id' => $po['id'],
                    'order_number' => $po['order_number'],
                    'tracking_token' => $po['tracking_token'],
                    'total_amount' => $po['total_amount'],
                    'customer_notes' => $po['customer_notes'],
                    'created_at' => $po['created_at'],
                    'idempotency_key' => "hub_ord_{$po['id']}",
                    'items' => $items
                ];
            }

            $updatesPayload = [];
            foreach ($pendingUpdates as $pu) {
                $updatesPayload[] = [
                    'order_id' => $pu['order_id'],
                    'new_status' => $pu['new_status'],
                    'transition_time' => $pu['transition_time']
                ];
            }

            // Generate Batch ID
            $batchId = 'batch_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

            $syncPayload = [
                'business_id' => $businessId,
                'device_uuid' => $deviceUuid,
                'device_secret' => $deviceSecret,
                'batch_id' => $batchId,
                'orders' => $ordersPayload,
                'status_updates' => $updatesPayload
            ];

            $syncOptions = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => json_encode($syncPayload),
                    'timeout' => 8
                ]
            ];

            $syncResRaw = @file_get_contents("{$cloudUrl}/api/hub/sync", false, stream_context_create($syncOptions));

            if ($syncResRaw !== false) {
                $syncRes = json_decode($syncResRaw, true);
                if ($syncRes && isset($syncRes['status']) && $syncRes['status'] === 'success') {
                    // Mark local records as synced
                    $db->exec("UPDATE local_orders SET sync_state = 'synced' WHERE sync_state = 'pending_sync'");
                    $db->exec("UPDATE local_status_updates SET sync_state = 'synced' WHERE sync_state = 'pending_sync'");

                    $b = $syncRes['batch'];
                    echo "[" . date('H:i:s') . "] Batch {$batchId} SUCCESS! Synced: {$b['orders_processed']} orders, {$b['updates_processed']} updates, {$b['conflicts']} conflicts.\n";
                } else {
                    echo "[" . date('H:i:s') . "] Sync rejected by cloud: " . ($syncRes['error'] ?? 'Unknown error') . "\n";
                }
            } else {
                echo "[" . date('H:i:s') . "] Sync HTTP POST failed. Will retry next cycle.\n";
            }
        }
    } catch (\Throwable $e) {
        echo "[" . date('H:i:s') . "] Worker error: " . $e->getMessage() . "\n";
    }

    sleep($interval);
}
