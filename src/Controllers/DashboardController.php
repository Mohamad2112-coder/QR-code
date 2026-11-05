<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Middleware\TenantMiddleware;

class DashboardController
{
    public function index(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        // 1. Order counts by status for today
        $stmtToday = $db->prepare("
            SELECT 
                COUNT(*) as total_today,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as preparing_count,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_count,
                SUM(CASE WHEN status = 'collected' THEN 1 ELSE 0 END) as collected_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END), 0) as today_revenue
            FROM orders 
            WHERE business_id = ? 
            AND created_at >= CURDATE()
        ");
        $stmtToday->execute([$businessId]);
        $stats = $stmtToday->fetch();

        // 2. Average preparation time in minutes for today (from created_at to ready_at)
        $stmtAvgPrep = $db->prepare("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, ready_at)) as avg_prep_minutes
            FROM orders
            WHERE business_id = ?
            AND ready_at IS NOT NULL
            AND created_at >= CURDATE()
        ");
        $stmtAvgPrep->execute([$businessId]);
        $avgPrepRow = $stmtAvgPrep->fetch();
        $avgPrepTime = $avgPrepRow['avg_prep_minutes'] ? round((float)$avgPrepRow['avg_prep_minutes'], 1) : 0;

        // 3. Orders waiting too long (> 15 minutes in pending or preparing status)
        $stmtLongWait = $db->prepare("
            SELECT COUNT(*) 
            FROM orders 
            WHERE business_id = ? 
            AND status IN ('pending', 'preparing')
            AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmtLongWait->execute([$businessId]);
        $waitingTooLongCount = (int)$stmtLongWait->fetchColumn();

        // 4. Recent orders list
        $stmtRecent = $db->prepare("
            SELECT id, order_number, tracking_token, status, total_amount, is_offline_created, created_at
            FROM orders
            WHERE business_id = ?
            ORDER BY created_at DESC
            LIMIT 8
        ");
        $stmtRecent->execute([$businessId]);
        $recentOrders = $stmtRecent->fetchAll();

        // 5. Subscription info
        $stmtSub = $db->prepare("
            SELECT s.*, sp.name as plan_name, sp.max_orders_per_month
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.business_id = ?
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        $stmtSub->execute([$businessId]);
        $subscription = $stmtSub->fetch();

        // 6. Tenant info
        $tenant = TenantMiddleware::getTenant();

        $html = View::render('dashboard/index', [
            'title' => 'Dashboard - QR Order Pager',
            'stats' => $stats,
            'avgPrepTime' => $avgPrepTime,
            'waitingTooLongCount' => $waitingTooLongCount,
            'recentOrders' => $recentOrders,
            'subscription' => $subscription,
            'tenant' => $tenant
        ]);

        $response->html($html)->send();
    }
}
