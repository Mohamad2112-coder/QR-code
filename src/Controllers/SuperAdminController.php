<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Core\Session;
use App\Services\SubscriptionService;
use App\Services\BackupService;
use App\Services\AuditService;

class SuperAdminController
{
    public function index(Request $request, Response $response): void
    {
        $db = Database::getConnection();

        // 1. Platform-wide Metrics
        $stmtBiz = $db->query("
            SELECT 
                COUNT(*) as total_businesses,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial_count,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_count
            FROM businesses
        ");
        $bizStats = $stmtBiz->fetch();

        $stmtOrders = $db->query("
            SELECT COUNT(*) as total_platform_orders, COALESCE(SUM(total_amount), 0) as total_platform_gmv
            FROM orders
            WHERE status != 'cancelled'
        ");
        $orderStats = $stmtOrders->fetch();

        $stmtRev = $db->query("
            SELECT COALESCE(SUM(amount), 0) as verified_revenue
            FROM subscription_payments
            WHERE status = 'verified'
        ");
        $revStats = $stmtRev->fetch();

        // 2. Businesses list
        $stmtBizList = $db->query("
            SELECT b.*, 
                   (SELECT name FROM users WHERE business_id = b.id AND role_id = (SELECT id FROM roles WHERE name = 'owner') LIMIT 1) as owner_name,
                   (SELECT COUNT(*) FROM orders WHERE business_id = b.id) as orders_count,
                   sp.name as plan_name, s.status as sub_status
            FROM businesses b
            LEFT JOIN subscriptions s ON s.business_id = b.id
            LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
            ORDER BY b.created_at DESC
        ");
        $businesses = $stmtBizList->fetchAll();

        // 3. Subscription Plans
        $stmtPlans = $db->query("SELECT * FROM subscription_plans ORDER BY monthly_price ASC");
        $plans = $stmtPlans->fetchAll();

        // 4. Pending Payments for Verification
        $stmtPayments = $db->query("
            SELECT p.*, b.name as business_name, sp.name as plan_name
            FROM subscription_payments p
            JOIN businesses b ON p.business_id = b.id
            JOIN subscription_plans sp ON p.plan_id = sp.id
            ORDER BY p.created_at DESC
        ");
        $payments = $stmtPayments->fetchAll();

        // 5. Backups
        $backups = BackupService::listBackups();

        // 6. Audit Logs
        $stmtAudit = $db->query("
            SELECT al.*, b.name as business_name, u.email as user_email
            FROM audit_logs al
            LEFT JOIN businesses b ON al.business_id = b.id
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $auditLogs = $stmtAudit->fetchAll();

        $html = View::render('superadmin/index', [
            'title' => 'Platform Super Admin Portal',
            'bizStats' => $bizStats,
            'orderStats' => $orderStats,
            'revStats' => $revStats,
            'businesses' => $businesses,
            'plans' => $plans,
            'payments' => $payments,
            'backups' => $backups,
            'auditLogs' => $auditLogs,
            'success' => Session::flash('success'),
            'error' => Session::flash('error')
        ], 'superadmin');

        $response->html($html)->send();
    }

    public function toggleBusinessStatus(Request $request, Response $response, array $params): void
    {
        $bizId = $params['id'];
        $newStatus = trim((string)$request->input('status'));

        if (in_array($newStatus, ['active', 'trial', 'suspended'], true)) {
            $db = Database::getConnection();
            $db->prepare("UPDATE businesses SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newStatus, $bizId]);
            AuditService::log('business_status_changed', "Super Admin changed business {$bizId} status to {$newStatus}", $bizId);
            Session::flash('success', "Business status updated to {$newStatus}.");
        }

        $response->redirect('/superadmin')->send();
    }

    public function updatePlan(Request $request, Response $response, array $params): void
    {
        $planId = $params['id'];
        $monthlyPrice = max(0.00, (float)$request->input('monthly_price'));
        $yearlyPrice = max(0.00, (float)$request->input('yearly_price'));
        $maxOrders = (int)$request->input('max_orders_per_month', 500);
        $maxStaff = (int)$request->input('max_staff_members', 5);
        $maxDevices = (int)$request->input('max_devices', 3);
        $allowOfflineHub = $request->input('allow_offline_hub') !== null ? 1 : 0;

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE subscription_plans
            SET monthly_price = ?, yearly_price = ?, max_orders_per_month = ?, max_staff_members = ?, max_devices = ?, allow_offline_hub = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$monthlyPrice, $yearlyPrice, $maxOrders, $maxStaff, $maxDevices, $allowOfflineHub, $planId]);

        AuditService::log('subscription_plan_updated', "Super Admin updated plan ID {$planId}");
        Session::flash('success', "Subscription plan updated.");
        $response->redirect('/superadmin')->send();
    }

    public function verifyPayment(Request $request, Response $response, array $params): void
    {
        $paymentId = $params['id'];
        $action = $request->input('action', 'verify');

        $db = Database::getConnection();
        if ($action === 'verify') {
            SubscriptionService::verifyPayment($paymentId, Auth::id());
            Session::flash('success', 'Payment verified and business subscription renewed!');
        } else {
            $db->prepare("UPDATE subscription_payments SET status = 'rejected', updated_at = NOW() WHERE id = ?")
                ->execute([$paymentId]);
            Session::flash('success', 'Payment marked as rejected.');
        }

        $response->redirect('/superadmin')->send();
    }

    public function createBackup(Request $request, Response $response): void
    {
        try {
            $res = BackupService::createBackup();
            Session::flash('success', "Automated backup generated: {$res['filename']} ({$res['tables_count']} tables).");
        } catch (\Throwable $e) {
            Session::flash('error', "Backup failed: " . $e->getMessage());
        }

        $response->redirect('/superadmin')->send();
    }
}
