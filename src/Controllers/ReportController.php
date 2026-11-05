<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Middleware\TenantMiddleware;

class ReportController
{
    public function index(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        $days = (int)($request->query('days', 30));
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        // 1. Daily Volume Trend (Last N days)
        $stmtDaily = $db->prepare("
            SELECT DATE(created_at) as order_date, COUNT(*) as orders_count, SUM(total_amount) as daily_revenue
            FROM orders
            WHERE business_id = ? AND created_at >= ? AND status != 'cancelled'
            GROUP BY DATE(created_at)
            ORDER BY order_date ASC
        ");
        $stmtDaily->execute([$businessId, $startDate]);
        $dailyVolume = $stmtDaily->fetchAll();

        // 2. Busy Hours Distribution (00 - 23)
        $stmtHours = $db->prepare("
            SELECT HOUR(created_at) as hour_of_day, COUNT(*) as orders_count
            FROM orders
            WHERE business_id = ? AND created_at >= ?
            GROUP BY HOUR(created_at)
            ORDER BY hour_of_day ASC
        ");
        $stmtHours->execute([$businessId, $startDate]);
        $busyHours = $stmtHours->fetchAll();

        // 3. Prep & Collection Times
        $stmtTimes = $db->prepare("
            SELECT 
                AVG(TIMESTAMPDIFF(MINUTE, created_at, ready_at)) as avg_prep_time,
                AVG(TIMESTAMPDIFF(MINUTE, ready_at, collected_at)) as avg_pickup_time
            FROM orders
            WHERE business_id = ? AND created_at >= ? AND ready_at IS NOT NULL
        ");
        $stmtTimes->execute([$businessId, $startDate]);
        $times = $stmtTimes->fetch();

        // 4. Product Popularity Ranking
        $stmtProducts = $db->prepare("
            SELECT oi.product_name_snapshot as product_name, SUM(oi.quantity) as total_qty, SUM(oi.subtotal) as total_sales
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.business_id = ? AND o.created_at >= ? AND o.status != 'cancelled'
            GROUP BY oi.product_name_snapshot
            ORDER BY total_qty DESC
            LIMIT 10
        ");
        $stmtProducts->execute([$businessId, $startDate]);
        $popularProducts = $stmtProducts->fetchAll();

        // 5. Staff Performance
        $stmtStaff = $db->prepare("
            SELECT u.name as staff_name, r.name as role_name, COUNT(o.id) as orders_handled, SUM(o.total_amount) as sales_handled
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN orders o ON o.created_by_user_id = u.id AND o.created_at >= ? AND o.status != 'cancelled'
            WHERE u.business_id = ?
            GROUP BY u.id, u.name, r.name
            ORDER BY orders_handled DESC
        ");
        $stmtStaff->execute([$startDate, $businessId]);
        $staffStats = $stmtStaff->fetchAll();

        // 6. Cancellation Statistics
        $stmtCancel = $db->prepare("
            SELECT COUNT(*) as cancelled_count, SUM(total_amount) as lost_revenue
            FROM orders
            WHERE business_id = ? AND status = 'cancelled' AND created_at >= ?
        ");
        $stmtCancel->execute([$businessId, $startDate]);
        $cancellationStats = $stmtCancel->fetch();

        // 7. Push Notification & Offline Sync Stats
        $stmtNotif = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM push_subscriptions WHERE business_id = ? AND is_active = 1) as active_subscriptions,
                (SELECT COUNT(*) FROM notification_jobs WHERE business_id = ? AND status = 'sent') as sent_notifications,
                (SELECT COUNT(*) FROM orders WHERE business_id = ? AND is_offline_created = 1) as offline_orders_count,
                (SELECT COUNT(*) FROM sync_batches WHERE business_id = ?) as total_sync_batches
        ");
        $stmtNotif->execute([$businessId, $businessId, $businessId, $businessId]);
        $systemStats = $stmtNotif->fetch();

        // 8. Recent Sync Batches
        $stmtBatches = $db->prepare("
            SELECT * FROM sync_batches 
            WHERE business_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmtBatches->execute([$businessId]);
        $syncBatches = $stmtBatches->fetchAll();

        $html = View::render('dashboard/reports', [
            'title' => 'Reports & Analytics - QR Order Pager',
            'days' => $days,
            'dailyVolume' => $dailyVolume,
            'busyHours' => $busyHours,
            'times' => $times,
            'popularProducts' => $popularProducts,
            'staffStats' => $staffStats,
            'cancellationStats' => $cancellationStats,
            'systemStats' => $systemStats,
            'syncBatches' => $syncBatches,
            'tenant' => TenantMiddleware::getTenant()
        ]);

        $response->html($html)->send();
    }

    /**
     * Export Orders to CSV
     */
    public function exportCsv(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT o.order_number, o.status, o.total_amount, o.is_offline_created, o.created_at, o.preparing_at, o.ready_at, o.collected_at, u.name as staff_name
            FROM orders o
            LEFT JOIN users u ON o.created_by_user_id = u.id
            WHERE o.business_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$businessId]);
        $orders = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=orders_export_' . date('Y_m_d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Order Number', 'Status', 'Total ($)', 'Offline Created', 'Created At', 'Preparing At', 'Ready At', 'Collected At', 'Staff']);

        foreach ($orders as $row) {
            fputcsv($out, [
                $row['order_number'],
                strtoupper($row['status']),
                $row['total_amount'],
                $row['is_offline_created'] ? 'Yes' : 'No',
                $row['created_at'],
                $row['preparing_at'] ?? '',
                $row['ready_at'] ?? '',
                $row['collected_at'] ?? '',
                $row['staff_name'] ?? 'System'
            ]);
        }
        fclose($out);
        exit;
    }
}
