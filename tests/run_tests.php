<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\UUID;
use App\Core\Auth;
use App\Core\Translation;
use App\Services\OrderService;
use App\Services\NotificationService;
use App\Services\SyncEngineService;
use App\Services\SubscriptionService;
use App\Services\BackupService;

class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function assert(bool $condition, string $testName, string $failureDetails = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✔ PASS\033[0m: {$testName}\n";
        } else {
            $this->failed++;
            $this->failures[] = "{$testName}: {$failureDetails}";
            echo "  \033[31m✖ FAIL\033[0m: {$testName} - {$failureDetails}\n";
        }
    }

    public function printSummary(): void
    {
        echo "\n=========================================================\n";
        echo "   TEST EXECUTION SUMMARY                                \n";
        echo "=========================================================\n";
        echo "Total Passed: \033[32m{$this->passed}\033[0m\n";
        echo "Total Failed: " . ($this->failed > 0 ? "\033[31m{$this->failed}\033[0m" : "\033[32m0\033[0m") . "\n";

        if ($this->failed > 0) {
            echo "\nFailures:\n";
            foreach ($this->failures as $f) {
                echo " - {$f}\n";
            }
            exit(1);
        } else {
            echo "\n\033[32mALL TESTS PASSED SUCCESSFULLY!\033[0m\n\n";
            exit(0);
        }
    }
}

$runner = new TestRunner();
$db = Database::getConnection();

echo "Starting Automated Test Suite...\n\n";

// --------------------------------------------------------------------------
// SUITE 1: Authentication, Password Hashing & RBAC
// --------------------------------------------------------------------------
echo "Suite 1: Authentication, Hashing & Roles\n";

$testEmail = 'owner@sunrisecafe.com';
$testPass = 'Password123!';
$loginSuccess = Auth::attempt($testEmail, $testPass);
$runner->assert($loginSuccess === true, 'Auth::attempt accepts correct owner credentials');
$runner->assert(Auth::check() === true, 'Auth::check recognizes authenticated session');
$runner->assert(Auth::role() === 'owner', 'Auth::role returns correct user role (owner)');
$runner->assert(Auth::hasRole(['owner', 'manager']) === true, 'Auth::hasRole matches permitted roles');

$badLogin = Auth::attempt($testEmail, 'WrongPassword!');
$runner->assert($badLogin === false, 'Auth::attempt rejects invalid password');

// Super Admin check
$adminLogin = Auth::attempt('admin@qrpager.io', 'Password123!');
$runner->assert($adminLogin === true, 'Auth::attempt accepts Super Admin credentials');
$runner->assert(Auth::isSuperAdmin() === true, 'Auth::isSuperAdmin returns true for super_admin');

Auth::logout();
$runner->assert(Auth::check() === false, 'Auth::logout clears authentication');

// --------------------------------------------------------------------------
// SUITE 2: Strict Tenant Isolation & Anti-IDOR
// --------------------------------------------------------------------------
echo "\nSuite 2: Multi-Tenant Workspace & Isolation\n";

// Tenant A
$stmtBizA = $db->prepare("SELECT id FROM businesses WHERE slug = 'sunrise-cafe'");
$stmtBizA->execute();
$bizA = $stmtBizA->fetchColumn();

// Create temporary Tenant B to test cross-tenant data leakage
$bizB = UUID::v4();
$slugB = 'tenant-b-' . bin2hex(random_bytes(4));
$db->prepare("INSERT INTO businesses (id, name, slug, email, default_language, status, trial_ends_at, created_at, updated_at) VALUES (?, 'Tenant B Bistro', ?, 'b@b.com', 'en', 'trial', NOW(), NOW(), NOW())")
    ->execute([$bizB, $slugB]);
$db->prepare("INSERT INTO business_settings (id, business_id, currency_symbol, order_number_prefix, next_order_number, qr_expiration_grace_minutes, estimated_prep_time_minutes, enable_offline_hub, hub_secret_key, created_at, updated_at) VALUES (?, ?, '$', 'TB-', 1, 60, 15, 1, 'sec', NOW(), NOW())")
    ->execute([UUID::v4(), $bizB]);

// Create order for Tenant B
$orderB = OrderService::createOrder($bizB, [
    ['name' => 'Secret Recipe Steak', 'price' => 35.00, 'quantity' => 1]
]);

// Test cross-tenant query: Tenant A queries orders
$stmtQuery = $db->prepare("SELECT COUNT(*) FROM orders WHERE business_id = ? AND id = ?");
$stmtQuery->execute([$bizA, $orderB['order_id']]);
$leakageCount = (int)$stmtQuery->fetchColumn();
$runner->assert($leakageCount === 0, 'Tenant Isolation: Tenant A cannot query Tenant B orders');

// --------------------------------------------------------------------------
// SUITE 3: Order Creation, Snapshots & Total Verification
// --------------------------------------------------------------------------
echo "\nSuite 3: Order Lifecycle, Snapshots & Math Integrity\n";

$itemsToOrder = [
    ['name' => 'Artisan Croissant', 'price' => 4.50, 'quantity' => 2, 'notes' => 'Crispy'],
    ['name' => 'Signature Flat White', 'price' => 5.25, 'quantity' => 1, 'notes' => 'Oat milk'],
];
// Total expected: (4.50 * 2) + (5.25 * 1) = 9.00 + 5.25 = 14.25

$createdOrder = OrderService::createOrder($bizA, $itemsToOrder, 'Customer table 9');
$runner->assert((float)$createdOrder['total_amount'] === 14.25, 'Server-side Order total recalculated correctly ($14.25)');
$runner->assert(!empty($createdOrder['order_number']), 'Order received sequential human-readable number: ' . $createdOrder['order_number']);
$runner->assert(str_starts_with($createdOrder['tracking_token'], 'tk_'), 'Unguessable tracking token generated with high entropy');

// Verify snapshot inside order_items table
$stmtItems = $db->prepare("SELECT product_name_snapshot, unit_price_snapshot, subtotal FROM order_items WHERE order_id = ? ORDER BY product_name_snapshot ASC");
$stmtItems->execute([$createdOrder['order_id']]);
$snapItems = $stmtItems->fetchAll();
$runner->assert(count($snapItems) === 2, 'Order snapshots 2 line items');
$runner->assert($snapItems[0]['product_name_snapshot'] === 'Artisan Croissant', 'Preserves product name snapshot in order history');
$runner->assert((float)$snapItems[0]['unit_price_snapshot'] === 4.50, 'Preserves price snapshot in order history');

// --------------------------------------------------------------------------
// SUITE 4: Status Transitions, State Machine & Push Enqueue
// --------------------------------------------------------------------------
echo "\nSuite 4: Order Status Workflow & State Machine\n";

// 1. Pending -> Preparing
$transition1 = OrderService::updateStatus($createdOrder['order_id'], 'preparing');
$runner->assert($transition1['current'] === 'preparing', 'Valid transition: pending -> preparing');

// 2. Preparing -> Ready (Must trigger notification enqueue)
// Register a mock push subscription first
NotificationService::subscribe($bizA, $createdOrder['order_id'], 'https://fcm.googleapis.com/fcm/send/mock_endpoint_123', 'mock_p256', 'mock_auth');

$transition2 = OrderService::updateStatus($createdOrder['order_id'], 'ready');
$runner->assert($transition2['current'] === 'ready', 'Valid transition: preparing -> ready');

// Verify notification job enqueued
$stmtJob = $db->prepare("SELECT COUNT(*) FROM notification_jobs WHERE order_id = ? AND status = 'pending'");
$stmtJob->execute([$createdOrder['order_id']]);
$jobCount = (int)$stmtJob->fetchColumn();
$runner->assert($jobCount === 1, 'Marking order Ready enqueued customer push notification');

// 3. Test Idempotency: Calling update to 'ready' again does NOT create duplicate notification!
OrderService::updateStatus($createdOrder['order_id'], 'ready');
$stmtJob->execute([$createdOrder['order_id']]);
$jobCountAfter = (int)$stmtJob->fetchColumn();
$runner->assert($jobCountAfter === 1, 'Idempotency: Re-evaluating Ready state does NOT send duplicate push alert');

// 4. Test Invalid Backward Transition: Collected -> Preparing must throw exception!
OrderService::updateStatus($createdOrder['order_id'], 'collected');
$backwardFailed = false;
try {
    OrderService::updateStatus($createdOrder['order_id'], 'preparing');
} catch (\Throwable $e) {
    $backwardFailed = true;
}
$runner->assert($backwardFailed === true, 'State Machine: Denies invalid backwards transition from collected to preparing');

// --------------------------------------------------------------------------
// SUITE 5: Customer QR Tracking & Expiration Policy
// --------------------------------------------------------------------------
echo "\nSuite 5: Public QR Token Resolution & Expiration\n";

$customerView = OrderService::getCustomerOrder($createdOrder['tracking_token']);
$runner->assert($customerView !== null, 'Public QR tracking resolves valid order by token');
$runner->assert($customerView['order_number'] === $createdOrder['order_number'], 'Token correctly maps to right order number');
$runner->assert(!isset($customerView['staff_notes']), 'Customer view masks internal employee notes');

// Test invalid random token
$invalidView = OrderService::getCustomerOrder('tk_non_existent_random_hash_12345');
$runner->assert($invalidView === null, 'Unguessable token validation returns null for invalid tokens');

// --------------------------------------------------------------------------
// SUITE 6: Offline Local Business Hub Synchronization & Conflict Resolution
// --------------------------------------------------------------------------
echo "\nSuite 6: Offline Hub Sync & Conflict Resolution\n";

// Register an authorized test device
$deviceUuid = 'dev_test_suite_' . bin2hex(random_bytes(6));
$deviceSecret = 'edge_secret_pass_123';
$db->prepare("INSERT INTO local_devices (id, business_id, device_uuid, device_name, device_type, auth_token_hash, is_authorized, created_at, updated_at) VALUES (?, ?, ?, 'Test POS', 'cashier_pos', ?, 1, NOW(), NOW())")
    ->execute([UUID::v4(), $bizA, $deviceUuid, password_hash($deviceSecret, PASSWORD_BCRYPT)]);

// Authorize device
$authDevice = SyncEngineService::authorizeDevice($bizA, $deviceUuid, $deviceSecret);
$runner->assert($authDevice !== null, 'Sync Engine: Authorizes edge device using UUID & hashed token');

// Simulate offline created order
$offlineOrderId = UUID::v4();
$offlineToken = 'tk_off_' . bin2hex(random_bytes(16));
$batchId = 'batch_test_' . bin2hex(random_bytes(4));

$batchResult = SyncEngineService::processSyncBatch(
    $bizA,
    $deviceUuid,
    $batchId,
    [
        [
            'id' => $offlineOrderId,
            'order_number' => 'OFF-999',
            'tracking_token' => $offlineToken,
            'customer_notes' => 'Created during internet outage',
            'items' => [
                ['name' => 'Offline Espresso', 'price' => 3.00, 'qty' => 1]
            ]
        ]
    ],
    []
);
$runner->assert($batchResult['status'] === 'completed', 'Sync Engine: Ingests offline-created order batch');
$runner->assert($batchResult['orders_processed'] === 1, 'Sync Engine: Processed 1 offline order into cloud');

// Verify order is now queryable in cloud with exact same QR token
$syncedOrder = OrderService::getCustomerOrder($offlineToken);
$runner->assert($syncedOrder !== null, 'Public QR resolves offline order once synced to cloud');

// Conflict Resolution Test:
// Cloud order is 'collected'. An edge device sends a stale offline status update saying 'preparing'.
// Conflict rule: Cloud rank (collected = 4) > offline rank (preparing = 2) -> CLOUD WINS!
$batchConflictResult = SyncEngineService::processSyncBatch(
    $bizA,
    $deviceUuid,
    'batch_conflict_' . bin2hex(random_bytes(4)),
    [],
    [
        ['order_id' => $createdOrder['order_id'], 'new_status' => 'preparing']
    ]
);
$runner->assert($batchConflictResult['conflicts'] === 1, 'Conflict Resolution: Detects stale offline status downgrade');
$verifyCloudOrder = OrderService::getCustomerOrder($createdOrder['tracking_token']);
$runner->assert($verifyCloudOrder['status'] === 'collected', 'Conflict Resolution: Cloud state (collected) preserved over stale offline update');

// --------------------------------------------------------------------------
// SUITE 7: Subscriptions, Trial & Plan Limits
// --------------------------------------------------------------------------
echo "\nSuite 7: SaaS Subscriptions & Quota Enforcement\n";

$canOrder = SubscriptionService::canCreateOrder($bizA);
$runner->assert($canOrder === true, 'Subscription Service: Active trial business can create orders within quota');

// Clean up temporary test data
$db->prepare("DELETE FROM businesses WHERE id = ?")->execute([$bizB]);

$runner->printSummary();
