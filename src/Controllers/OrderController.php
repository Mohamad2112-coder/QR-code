<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Core\Session;
use App\Middleware\TenantMiddleware;
use App\Services\OrderService;
use App\Services\QRCodeService;
use App\Services\SubscriptionService;
use Exception;

class OrderController
{
    /**
     * Cashier POS Fast Order Creation Interface
     */
    public function createView(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        // Check subscription order quota
        if (!SubscriptionService::canCreateOrder($businessId)) {
            Session::flash('error', 'Monthly order limit reached for your subscription plan. Please upgrade to continue creating orders.');
        }

        // Fetch categories & available products
        $stmtCat = $db->prepare("SELECT * FROM product_categories WHERE business_id = ? AND is_active = 1 ORDER BY display_order ASC, name ASC");
        $stmtCat->execute([$businessId]);
        $categories = $stmtCat->fetchAll();

        $stmtProd = $db->prepare("
            SELECT id, category_id, name, description, price 
            FROM products 
            WHERE business_id = ? AND is_active = 1 AND is_available = 1
            ORDER BY display_order ASC, name ASC
        ");
        $stmtProd->execute([$businessId]);
        $products = $stmtProd->fetchAll();

        $html = View::render('orders/create', [
            'title' => 'Cashier POS - Create Order',
            'categories' => $categories,
            'products' => $products,
            'tenant' => TenantMiddleware::getTenant(),
            'error' => Session::flash('error'),
            'success' => Session::flash('success')
        ]);

        $response->html($html)->send();
    }

    /**
     * Store new order (Cashier submission)
     */
    public function store(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();

        if (!SubscriptionService::canCreateOrder($businessId)) {
            $msg = 'Monthly order limit reached for your plan. Please upgrade.';
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => $msg], 403)->send();
            } else {
                Session::flash('error', $msg);
                $response->redirect('/orders/create')->send();
            }
            return;
        }

        $items = $request->input('items', []);
        $customerNotes = trim((string)$request->input('customer_notes', ''));
        $staffNotes = trim((string)$request->input('staff_notes', ''));
        $idempotencyKey = $request->input('idempotency_key') ?: ('pos_ord_' . bin2hex(random_bytes(16)));

        if (empty($items)) {
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => 'Please select at least one product.'], 422)->send();
            } else {
                Session::flash('error', 'Order cannot be empty.');
                $response->redirect('/orders/create')->send();
            }
            return;
        }

        try {
            $result = OrderService::createOrder(
                $businessId,
                $items,
                $customerNotes,
                $staffNotes,
                Auth::id(),
                false,
                null,
                null,
                null,
                $idempotencyKey
            );

            // Generate QR Code data URI
            $qrDataUri = QRCodeService::generateSvgDataUri($result['tracking_url']);

            if ($request->isAjaxOrJson()) {
                $response->json([
                    'status' => 'success',
                    'order_id' => $result['order_id'],
                    'order_number' => $result['order_number'],
                    'tracking_token' => $result['tracking_token'],
                    'tracking_url' => $result['tracking_url'],
                    'total_amount' => number_format((float)$result['total_amount'], 2),
                    'qr_svg' => $qrDataUri
                ])->send();
            } else {
                Session::flash('success', "Order {$result['order_number']} created successfully.");
                $response->redirect("/orders/{$result['order_id']}")->send();
            }
        } catch (Exception $e) {
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => $e->getMessage()], 400)->send();
            } else {
                Session::flash('error', $e->getMessage());
                $response->redirect('/orders/create')->send();
            }
        }
    }

    /**
     * Orders List View
     */
    public function index(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        $statusFilter = $request->query('status');
        $search = trim((string)$request->query('q', ''));

        $sql = "
            SELECT o.*, u.name as cashier_name,
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count
            FROM orders o
            LEFT JOIN users u ON o.created_by_user_id = u.id
            WHERE o.business_id = ?
        ";
        $params = [$businessId];

        if (!empty($statusFilter) && in_array($statusFilter, ['pending', 'preparing', 'ready', 'collected', 'cancelled'], true)) {
            $sql .= " AND o.status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (o.order_number LIKE ? OR o.customer_notes LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= " ORDER BY o.created_at DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        $html = View::render('orders/index', [
            'title' => 'Orders List - QR Order Pager',
            'orders' => $orders,
            'currentStatus' => $statusFilter,
            'search' => $search,
            'success' => Session::flash('success'),
            'error' => Session::flash('error')
        ]);

        $response->html($html)->send();
    }

    /**
     * Single Order Details with Status History Timeline & QR
     */
    public function show(Request $request, Response $response, array $params): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $orderId = $params['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT o.*, u.name as cashier_name
            FROM orders o
            LEFT JOIN users u ON o.created_by_user_id = u.id
            WHERE o.id = ? AND o.business_id = ?
        ");
        $stmt->execute([$orderId, $businessId]);
        $order = $stmt->fetch();

        if (!$order) {
            $response->html('<h1>404 Not Found</h1><p>Order not found.</p>', 404)->send();
            return;
        }

        // Fetch Items
        $stmtItems = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY created_at ASC");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll();

        // Fetch Status History Timeline
        $stmtHist = $db->prepare("
            SELECT osh.*, u.name as user_name
            FROM order_status_history osh
            LEFT JOIN users u ON osh.changed_by_user_id = u.id
            WHERE osh.order_id = ?
            ORDER BY osh.created_at ASC
        ");
        $stmtHist->execute([$orderId]);
        $history = $stmtHist->fetchAll();

        $trackingUrl = QRCodeService::getTrackingUrl($order['tracking_token']);
        $qrSvgDataUri = QRCodeService::generateSvgDataUri($trackingUrl);

        $html = View::render('orders/view', [
            'title' => "Order #{$order['order_number']}",
            'order' => $order,
            'items' => $items,
            'history' => $history,
            'trackingUrl' => $trackingUrl,
            'qrSvg' => $qrSvgDataUri,
            'tenant' => TenantMiddleware::getTenant(),
            'success' => Session::flash('success'),
            'error' => Session::flash('error')
        ]);

        $response->html($html)->send();
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, Response $response, array $params): void
    {
        $orderId = $params['id'];
        $newStatus = trim((string)$request->input('status'));

        try {
            $res = OrderService::updateStatus($orderId, $newStatus, Auth::id(), 'cloud_web');
            if ($request->isAjaxOrJson()) {
                $response->json(['status' => 'success', 'data' => $res])->send();
            } else {
                Session::flash('success', "Order status updated to {$newStatus}.");
                $response->redirect("/orders/{$orderId}")->send();
            }
        } catch (Exception $e) {
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => $e->getMessage()], 400)->send();
            } else {
                Session::flash('error', $e->getMessage());
                $response->redirect("/orders/{$orderId}")->send();
            }
        }
    }
}
