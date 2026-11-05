<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Core\UUID;
use App\Core\Session;
use App\Middleware\TenantMiddleware;
use App\Services\AuditService;

class SettingsController
{
    public function index(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        // 1. Business Info & Settings
        $stmtBiz = $db->prepare("
            SELECT b.*, bs.currency_symbol, bs.order_number_prefix, bs.qr_expiration_grace_minutes,
                   bs.estimated_prep_time_minutes, bs.enable_offline_hub, bs.hub_secret_key,
                   bs.notification_ready_message_en, bs.notification_ready_message_ar, bs.notification_ready_message_ku,
                   bb.primary_color, bb.accent_color, bb.background_color, bb.logo_url, bb.tagline
            FROM businesses b
            LEFT JOIN business_settings bs ON b.id = bs.business_id
            LEFT JOIN business_branding bb ON b.id = bb.business_id
            WHERE b.id = ?
        ");
        $stmtBiz->execute([$businessId]);
        $business = $stmtBiz->fetch();

        // 2. Staff Accounts
        $stmtStaff = $db->prepare("
            SELECT u.*, r.name as role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.business_id = ?
            ORDER BY u.created_at ASC
        ");
        $stmtStaff->execute([$businessId]);
        $staff = $stmtStaff->fetchAll();

        // 3. Registered Local Devices
        $stmtDev = $db->prepare("SELECT * FROM local_devices WHERE business_id = ? ORDER BY created_at DESC");
        $stmtDev->execute([$businessId]);
        $devices = $stmtDev->fetchAll();

        // Roles available for staff assignment
        $stmtRoles = $db->prepare("SELECT id, name, description FROM roles WHERE name != 'super_admin'");
        $stmtRoles->execute();
        $roles = $stmtRoles->fetchAll();

        $html = View::render('settings/index', [
            'title' => 'Business Settings - QR Order Pager',
            'business' => $business,
            'staff' => $staff,
            'devices' => $devices,
            'roles' => $roles,
            'success' => Session::flash('success'),
            'error' => Session::flash('error')
        ]);

        $response->html($html)->send();
    }

    public function updateGeneral(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $name = trim((string)$request->input('name'));
        $phone = trim((string)$request->input('phone'));
        $defaultLang = trim((string)$request->input('default_language', 'en'));
        $currencySymbol = trim((string)$request->input('currency_symbol', '$'));
        $orderPrefix = trim((string)$request->input('order_number_prefix', 'ORD-'));
        $prepTime = max(1, (int)$request->input('estimated_prep_time_minutes', 15));
        $graceMinutes = max(5, (int)$request->input('qr_expiration_grace_minutes', 60));
        $msgEn = trim((string)$request->input('notification_ready_message_en'));
        $msgAr = trim((string)$request->input('notification_ready_message_ar'));
        $msgKu = trim((string)$request->input('notification_ready_message_ku'));

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE businesses SET name = ?, phone = ?, default_language = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$name, $phone, $defaultLang, $businessId]);

            $db->prepare("
                UPDATE business_settings 
                SET currency_symbol = ?, order_number_prefix = ?, estimated_prep_time_minutes = ?,
                    qr_expiration_grace_minutes = ?, notification_ready_message_en = ?,
                    notification_ready_message_ar = ?, notification_ready_message_ku = ?, updated_at = NOW()
                WHERE business_id = ?
            ")->execute([
                $currencySymbol, $orderPrefix, $prepTime, $graceMinutes,
                $msgEn, $msgAr, $msgKu, $businessId
            ]);

            $db->commit();
            AuditService::log('settings_updated', 'General business settings updated', $businessId);
            Session::flash('success', 'Settings saved successfully.');
        } catch (\Throwable $e) {
            $db->rollBack();
            Session::flash('error', 'Error updating settings: ' . $e->getMessage());
        }

        $response->redirect('/settings')->send();
    }

    public function updateBranding(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $primaryColor = trim((string)$request->input('primary_color', '#2563eb'));
        $accentColor = trim((string)$request->input('accent_color', '#f59e0b'));
        $tagline = trim((string)$request->input('tagline'));
        $logoUrl = trim((string)$request->input('logo_url'));

        $db = Database::getConnection();
        $db->prepare("
            UPDATE business_branding 
            SET primary_color = ?, accent_color = ?, tagline = ?, logo_url = ?, updated_at = NOW()
            WHERE business_id = ?
        ")->execute([$primaryColor, $accentColor, $tagline, $logoUrl, $businessId]);

        AuditService::log('branding_updated', 'Branding customizations updated', $businessId);
        Session::flash('success', 'Branding updated successfully.');
        $response->redirect('/settings')->send();
    }

    public function createStaff(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $name = trim((string)$request->input('name'));
        $email = trim((string)$request->input('email'));
        $password = (string)$request->input('password');
        $roleId = $request->input('role_id');

        if (empty($name) || empty($email) || strlen($password) < 6 || empty($roleId)) {
            Session::flash('error', 'Please fill in all staff fields with a password of at least 6 characters.');
            $response->redirect('/settings')->send();
            return;
        }

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetchColumn()) {
            Session::flash('error', 'This email address is already in use.');
            $response->redirect('/settings')->send();
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO users (id, business_id, role_id, name, email, password_hash, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([
            UUID::v4(), $businessId, $roleId, $name, $email, password_hash($password, PASSWORD_BCRYPT)
        ]);

        AuditService::log('staff_created', "Staff account created for {$email}", $businessId);
        Session::flash('success', "Staff account created for {$name}.");
        $response->redirect('/settings')->send();
    }

    public function toggleStaff(Request $request, Response $response, array $params): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $staffId = $params['id'];

        if ($staffId === Auth::id()) {
            Session::flash('error', 'You cannot deactivate your own account.');
            $response->redirect('/settings')->send();
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ? AND business_id = ?");
        $stmt->execute([$staffId, $businessId]);

        AuditService::log('staff_status_toggled', "Staff account ID {$staffId} status changed", $businessId);
        Session::flash('success', 'Staff account status updated.');
        $response->redirect('/settings')->send();
    }

    public function registerDevice(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $deviceName = trim((string)$request->input('device_name'));
        $deviceType = trim((string)$request->input('device_type', 'cashier_pos'));

        if (empty($deviceName)) {
            Session::flash('error', 'Device name is required.');
            $response->redirect('/settings')->send();
            return;
        }

        $deviceUuid = 'dev_' . bin2hex(random_bytes(16));
        $deviceSecret = bin2hex(random_bytes(24));

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO local_devices (id, business_id, device_uuid, device_name, device_type, auth_token_hash, is_authorized, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([
            UUID::v4(), $businessId, $deviceUuid, $deviceName, $deviceType, password_hash($deviceSecret, PASSWORD_BCRYPT)
        ]);

        AuditService::log('device_registered', "Local device '{$deviceName}' registered ({$deviceUuid})", $businessId);
        Session::flash('success', "Device registered! UUID: {$deviceUuid} | Token: {$deviceSecret} (Save this token now; it won't be shown again).");
        $response->redirect('/settings')->send();
    }
}
