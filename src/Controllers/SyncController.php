<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Services\SyncEngineService;
use Exception;

class SyncController
{
    /**
     * Ingest offline sync batch from Local Business Hub
     */
    public function syncBatch(Request $request, Response $response): void
    {
        $businessId = trim((string)$request->input('business_id'));
        $deviceUuid = trim((string)$request->input('device_uuid'));
        $deviceSecret = trim((string)$request->input('device_secret'));
        $batchId = trim((string)$request->input('batch_id'));
        $offlineOrders = $request->input('orders', []);
        $statusUpdates = $request->input('status_updates', []);

        if (empty($businessId) || empty($deviceUuid) || empty($deviceSecret) || empty($batchId)) {
            $response->json(['error' => 'Missing device authentication or batch parameters.'], 400)->send();
            return;
        }

        // Authorize edge device
        $device = SyncEngineService::authorizeDevice($businessId, $deviceUuid, $deviceSecret);
        if (!$device) {
            $response->json(['error' => 'Unauthorized device. Please verify device UUID and secret.'], 401)->send();
            return;
        }

        try {
            $result = SyncEngineService::processSyncBatch(
                $businessId,
                $deviceUuid,
                $batchId,
                $offlineOrders,
                $statusUpdates
            );

            $response->json([
                'status' => 'success',
                'batch' => $result,
                'server_time' => date('Y-m-d H:i:s')
            ])->send();
        } catch (Exception $e) {
            $response->json(['error' => 'Sync failed: ' . $e->getMessage()], 500)->send();
        }
    }

    /**
     * Return active product catalog for Local Business Hub local cache
     */
    public function pullCatalog(Request $request, Response $response): void
    {
        $businessId = trim((string)$request->query('business_id'));
        $deviceUuid = trim((string)$request->query('device_uuid'));
        $deviceSecret = trim((string)$request->query('device_secret'));

        $device = SyncEngineService::authorizeDevice($businessId, $deviceUuid, $deviceSecret);
        if (!$device) {
            $response->json(['error' => 'Unauthorized device.'], 401)->send();
            return;
        }

        $db = Database::getConnection();
        $stmtCat = $db->prepare("SELECT id, name, display_order FROM product_categories WHERE business_id = ? AND is_active = 1 ORDER BY display_order ASC");
        $stmtCat->execute([$businessId]);
        $categories = $stmtCat->fetchAll();

        $stmtProd = $db->prepare("
            SELECT id, category_id, name, description, price, is_available, display_order
            FROM products
            WHERE business_id = ? AND is_active = 1
            ORDER BY display_order ASC, name ASC
        ");
        $stmtProd->execute([$businessId]);
        $products = $stmtProd->fetchAll();

        $response->json([
            'status' => 'success',
            'categories' => $categories,
            'products' => $products,
            'synced_at' => date('Y-m-d H:i:s')
        ])->send();
    }
}
