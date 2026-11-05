<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Translation;
use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Middleware\TenantMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\CSRFMiddleware;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ProductController;
use App\Controllers\OrderController;
use App\Controllers\KitchenController;
use App\Controllers\CustomerTrackingController;
use App\Controllers\NotificationController;
use App\Controllers\ReportController;
use App\Controllers\SettingsController;
use App\Controllers\SuperAdminController;
use App\Controllers\SyncController;

// Start session
Session::start();

// Set locale from session if present
if (Session::has('app_locale')) {
    Translation::setLocale(Session::get('app_locale'));
}

$request = new Request();
$response = new Response();
$router = new Router();

// ----------------------------------------------------
// Public & Authentication Routes
// ----------------------------------------------------
$router->get('/', function (Request $req, Response $res) {
    if (Auth::check()) {
        if (Auth::isSuperAdmin()) {
            $res->redirect('/superadmin')->send();
        } else {
            $res->redirect('/dashboard')->send();
        }
    } else {
        $res->redirect('/login')->send();
    }
});

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], [CSRFMiddleware::class]);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register'], [CSRFMiddleware::class]);
$router->get('/set-locale', [AuthController::class, 'setLocale']);

// ----------------------------------------------------
// Public Customer Tracking & Notification APIs
// ----------------------------------------------------
$router->get('/track/{token}', [CustomerTrackingController::class, 'track']);
$router->get('/api/customer/status/{token}', [CustomerTrackingController::class, 'statusApi']);
$router->post('/api/customer/push-subscribe', [NotificationController::class, 'subscribe']);
$router->get('/api/vapid-key', [NotificationController::class, 'getVapidKey']);
$router->get('/api/notifications/run-queue', [NotificationController::class, 'runQueue']);
$router->post('/api/notifications/run-queue', [NotificationController::class, 'runQueue']);

// ----------------------------------------------------
// Local Business Hub Synchronization API
// ----------------------------------------------------
$router->post('/api/hub/sync', [SyncController::class, 'syncBatch']);
$router->get('/api/hub/catalog', [SyncController::class, 'pullCatalog']);

// ----------------------------------------------------
// Tenant Back-Office Protected Routes
// ----------------------------------------------------
$tenantMiddlewares = [
    AuthMiddleware::class,
    TenantMiddleware::class,
    SubscriptionMiddleware::class,
    CSRFMiddleware::class
];

// Dashboard
$router->get('/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);

// Products Catalog
$router->get('/products', [ProductController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);
$router->post('/products', [ProductController::class, 'create'], $tenantMiddlewares);
$router->post('/products/{id}/update', [ProductController::class, 'update'], $tenantMiddlewares);
$router->post('/products/{id}/toggle', [ProductController::class, 'toggleAvailability'], $tenantMiddlewares);
$router->post('/products/{id}/delete', [ProductController::class, 'delete'], $tenantMiddlewares);
$router->post('/categories', [ProductController::class, 'createCategory'], $tenantMiddlewares);

// Orders & POS
$router->get('/orders', [OrderController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);
$router->get('/orders/create', [OrderController::class, 'createView'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);
$router->post('/orders', [OrderController::class, 'store'], $tenantMiddlewares);
$router->get('/orders/{id}', [OrderController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);
$router->post('/orders/{id}/status', [OrderController::class, 'updateStatus'], $tenantMiddlewares);

// Kitchen Display System (KDS)
$router->get('/kitchen', [KitchenController::class, 'display'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/api/kitchen/orders', [KitchenController::class, 'activeOrdersApi'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/api/kitchen/orders/{id}/status', [KitchenController::class, 'updateOrderStatus'], [AuthMiddleware::class, TenantMiddleware::class]);

// Reports & Analytics
$router->get('/reports', [ReportController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);
$router->get('/reports/export', [ReportController::class, 'exportCsv'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);

// Settings & Staff
$router->get('/settings', [SettingsController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class, SubscriptionMiddleware::class]);
$router->post('/settings/general', [SettingsController::class, 'updateGeneral'], $tenantMiddlewares);
$router->post('/settings/branding', [SettingsController::class, 'updateBranding'], $tenantMiddlewares);
$router->post('/settings/staff', [SettingsController::class, 'createStaff'], $tenantMiddlewares);
$router->post('/settings/staff/{id}/toggle', [SettingsController::class, 'toggleStaff'], $tenantMiddlewares);
$router->post('/settings/devices', [SettingsController::class, 'registerDevice'], $tenantMiddlewares);

// ----------------------------------------------------
// Platform Super Admin Routes (Role: super_admin)
// ----------------------------------------------------
$superAdminMiddlewares = [
    AuthMiddleware::class,
    CSRFMiddleware::class
];

$router->get('/superadmin', [SuperAdminController::class, 'index'], [AuthMiddleware::class]);
$router->post('/superadmin/businesses/{id}/status', [SuperAdminController::class, 'toggleBusinessStatus'], $superAdminMiddlewares);
$router->post('/superadmin/plans/{id}', [SuperAdminController::class, 'updatePlan'], $superAdminMiddlewares);
$router->post('/superadmin/payments/{id}/verify', [SuperAdminController::class, 'verifyPayment'], $superAdminMiddlewares);
$router->post('/superadmin/backups', [SuperAdminController::class, 'createBackup'], $superAdminMiddlewares);

// Dispatch
$router->dispatch($request, $response);
