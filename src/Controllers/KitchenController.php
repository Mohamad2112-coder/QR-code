<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Middleware\TenantMiddleware;
use App\Services\OrderService;
use Exception;

class KitchenController
{
    /**
     * KDS Screen View
     */
    public function display(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        $orders = $this->getActiveKitchenOrders($businessId);

        $html = View::render('kitchen/display', [
            'title' => 'Kitchen Display System (KDS)',
            'orders' => $orders,
            'tenant' => TenantMiddleware::getTenant(),
        ], 'kitchen');

        $response->html($html)->send();
    }

    /**
     * JSON API for Kitchen Live Refresh / Polling
     */
    public function activeOrdersApi(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $orders = $this->getActiveKitchenOrders($businessId);
        $response->json(['status' => 'success', 'orders' => $orders])->send();
    }

    /**
     * KDS One-tap Status Action
     */
    public function updateOrderStatus(Request $request, Response $response, array $params): void
    {
        $orderId = $params['id'];
        $newStatus = trim((string)$request->input('status'));

        try {
            $result = OrderService::updateStatus($orderId, $newStatus, Auth::id(), 'kitchen_terminal');
            $response->json(['status' => 'success', 'result' => $result])->send();
        } catch (Exception $e) {
            $response->json(['error' => $e->getMessage()], 400)->send();
        }
    }

    private function getActiveKitchenOrders(string $businessId): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT id, order_number, status, customer_notes, staff_notes, created_at, preparing_at, ready_at,
                   TIMESTAMPDIFF(SECOND, created_at, NOW()) as elapsed_seconds
            FROM orders
            WHERE business_id = ? 
            AND status IN ('pending', 'preparing', 'ready')
            ORDER BY 
                CASE status 
                    WHEN 'ready' THEN 1 
                    WHEN 'preparing' THEN 2 
                    WHEN 'pending' THEN 3 
                END,
                created_at ASC
        ");
        $stmt->execute([$businessId]);
        $orders = $stmt->fetchAll();

        if (empty($orders)) {
            return [];
        }

        $orderIds = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $stmtItems = $db->prepare("
            SELECT order_id, product_name_snapshot, quantity, item_notes
            FROM order_items
            WHERE order_id IN ({$placeholders})
            ORDER BY created_at ASC
        ");
        $stmtItems->execute($orderIds);
        $allItems = $stmtItems->fetchAll();

        $itemsByOrder = [];
        foreach ($allItems as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }

        foreach ($orders as &$ord) {
            $ord['items'] = $itemsByOrder[$ord['id']] ?? [];
        }

        return $orders;
    }
}
