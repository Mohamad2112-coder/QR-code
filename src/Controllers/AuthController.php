<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Session;
use App\Core\Translation;
use App\Core\Database;
use App\Core\UUID;
use App\Services\SubscriptionService;
use App\Services\AuditService;

class AuthController
{
    public function showLogin(Request $request, Response $response): void
    {
        if (Auth::check()) {
            if (Auth::isSuperAdmin()) {
                $response->redirect('/superadmin')->send();
            } else {
                $response->redirect('/dashboard')->send();
            }
            return;
        }

        $html = View::render('auth/login', [
            'title' => 'Sign In - QR Order Pager',
            'error' => Session::flash('error')
        ], 'auth');

        $response->html($html)->send();
    }

    public function login(Request $request, Response $response): void
    {
        $email = trim((string)$request->input('email'));
        $password = (string)$request->input('password');

        if (empty($email) || empty($password)) {
            Session::flash('error', 'Please enter your email and password.');
            $response->redirect('/login')->send();
            return;
        }

        if (Auth::attempt($email, $password)) {
            AuditService::log('user_login_success', "User {$email} logged in successfully");

            if (Auth::isSuperAdmin()) {
                $response->redirect('/superadmin')->send();
            } else {
                $response->redirect('/dashboard')->send();
            }
            return;
        }

        AuditService::log('user_login_failed', "Failed login attempt for {$email}");
        Session::flash('error', 'Invalid email or password. Please try again.');
        $response->redirect('/login')->send();
    }

    public function logout(Request $request, Response $response): void
    {
        AuditService::log('user_logout', "User logged out");
        Auth::logout();
        $response->redirect('/login')->send();
    }

    public function showRegister(Request $request, Response $response): void
    {
        $html = View::render('auth/register', [
            'title' => 'Register Business - QR Order Pager SaaS',
            'error' => Session::flash('error')
        ], 'auth');

        $response->html($html)->send();
    }

    public function register(Request $request, Response $response): void
    {
        $businessName = trim((string)$request->input('business_name'));
        $ownerName = trim((string)$request->input('owner_name'));
        $email = trim((string)$request->input('email'));
        $password = (string)$request->input('password');
        $phone = trim((string)$request->input('phone'));
        $language = trim((string)$request->input('default_language', 'en'));

        if (empty($businessName) || empty($ownerName) || empty($email) || strlen($password) < 8) {
            Session::flash('error', 'Please complete all required fields. Password must be at least 8 characters.');
            $response->redirect('/register')->send();
            return;
        }

        $db = Database::getConnection();

        // Check if email already registered
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetchColumn()) {
            Session::flash('error', 'An account with this email address already exists.');
            $response->redirect('/register')->send();
            return;
        }

        // Generate URL slug
        $baseSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $businessName));
        $baseSlug = trim($baseSlug, '-') ?: 'biz-' . bin2hex(random_bytes(3));
        $slug = $baseSlug;
        $counter = 1;

        $stmtSlug = $db->prepare("SELECT id FROM businesses WHERE slug = ?");
        while (true) {
            $stmtSlug->execute([$slug]);
            if (!$stmtSlug->fetchColumn()) {
                break;
            }
            $slug = $baseSlug . '-' . $counter++;
        }

        $db->beginTransaction();
        try {
            $businessId = UUID::v4();
            $trialEnd = date('Y-m-d H:i:s', strtotime('+14 days'));

            // 1. Create Business
            $stmtBiz = $db->prepare("
                INSERT INTO businesses (id, name, slug, email, phone, default_language, status, trial_ends_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'trial', ?, NOW(), NOW())
            ");
            $stmtBiz->execute([$businessId, $businessName, $slug, $email, $phone, $language, $trialEnd]);

            // 2. Default Settings
            $hubSecret = bin2hex(random_bytes(24));
            $stmtSet = $db->prepare("
                INSERT INTO business_settings (
                    id, business_id, currency_symbol, order_number_prefix, next_order_number,
                    qr_expiration_grace_minutes, estimated_prep_time_minutes, enable_offline_hub,
                    hub_secret_key, notification_ready_message_en, notification_ready_message_ar,
                    notification_ready_message_ku, created_at, updated_at
                ) VALUES (?, ?, '$', 'ORD-', 1, 60, 15, 1, ?, 
                    'Your order #{order_number} is READY! Please pick it up at the counter.',
                    'طلبك رقم {order_number} جاهز! يرجى الاستلام من المنصة.',
                    'داواکاریەکەت ژمارە {order_number} ئامادەیە! تکایە لە کاونتەر وەریگرە.',
                    NOW(), NOW())
            ");
            $stmtSet->execute([UUID::v4(), $businessId, $hubSecret]);

            // 3. Default Branding
            $db->prepare("
                INSERT INTO business_branding (id, business_id, primary_color, accent_color, background_color, tagline, created_at, updated_at)
                VALUES (?, ?, '#2563eb', '#f59e0b', '#f8fafc', 'Digital QR Order Pager', NOW(), NOW())
            ")->execute([UUID::v4(), $businessId]);

            // 4. Create Owner Role & User
            $stmtRole = $db->prepare("SELECT id FROM roles WHERE name = 'owner'");
            $stmtRole->execute();
            $ownerRoleId = $stmtRole->fetchColumn();

            $ownerUserId = UUID::v4();
            $db->prepare("
                INSERT INTO users (id, business_id, role_id, name, email, password_hash, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ")->execute([
                $ownerUserId,
                $businessId,
                $ownerRoleId,
                $ownerName,
                $email,
                password_hash($password, PASSWORD_BCRYPT)
            ]);

            // 5. Start 14-Day Free Trial
            SubscriptionService::startTrial($businessId, 'starter');

            // 6. Create Default Category
            $db->prepare("INSERT INTO product_categories (id, business_id, name, display_order, is_active, created_at, updated_at) VALUES (?, ?, 'General Menu', 1, 1, NOW(), NOW())")
                ->execute([UUID::v4(), $businessId]);

            $db->commit();

            // Log user in directly
            Auth::attempt($email, $password);

            AuditService::log('business_registered', "New business '{$businessName}' registered by {$email}", $businessId, $ownerUserId);

            $response->redirect('/dashboard')->send();
        } catch (\Throwable $e) {
            $db->rollBack();
            Session::flash('error', 'Registration failed: ' . $e->getMessage());
            $response->redirect('/register')->send();
        }
    }

    public function setLocale(Request $request, Response $response): void
    {
        $locale = $request->query('lang') ?? $request->input('lang');
        if ($locale && in_array($locale, ['en', 'ar', 'ku'], true)) {
            Session::set('app_locale', $locale);
            Translation::setLocale($locale);
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '/dashboard';
        $response->redirect($referer)->send();
    }
}
