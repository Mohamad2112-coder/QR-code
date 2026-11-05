<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\UUID;

function seedDatabase(PDO $pdo): void
{
    echo "Starting database seeding...\n";

    // 1. Roles
    $roles = [
        ['id' => UUID::v4(), 'name' => 'super_admin', 'description' => 'Platform Super Administrator with system-wide access'],
        ['id' => UUID::v4(), 'name' => 'owner', 'description' => 'Tenant Business Owner with full business access'],
        ['id' => UUID::v4(), 'name' => 'manager', 'description' => 'Business Manager with operational management rights'],
        ['id' => UUID::v4(), 'name' => 'cashier', 'description' => 'Front-of-house cashier for order taking & QR creation'],
        ['id' => UUID::v4(), 'name' => 'kitchen', 'description' => 'Kitchen/Preparation staff for order fulfillment'],
    ];

    $roleMap = [];
    $stmtRoleCheck = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmtRoleInsert = $pdo->prepare("INSERT INTO roles (id, name, description) VALUES (?, ?, ?)");

    foreach ($roles as $r) {
        $stmtRoleCheck->execute([$r['name']]);
        $existing = $stmtRoleCheck->fetchColumn();
        if ($existing) {
            $roleMap[$r['name']] = $existing;
        } else {
            $stmtRoleInsert->execute([$r['id'], $r['name'], $r['description']]);
            $roleMap[$r['name']] = $r['id'];
        }
    }
    echo "Roles seeded.\n";

    // 2. Subscription Plans
    $plans = [
        [
            'id' => UUID::v4(),
            'code' => 'starter',
            'name' => 'Starter Plan',
            'monthly_price' => 19.00,
            'yearly_price' => 190.00,
            'max_orders_per_month' => 500,
            'max_staff_members' => 5,
            'max_devices' => 3,
            'allow_offline_hub' => 1,
            'allow_advanced_branding' => 0,
            'allow_exports' => 0,
            'data_retention_days' => 60,
        ],
        [
            'id' => UUID::v4(),
            'code' => 'professional',
            'name' => 'Professional Plan',
            'monthly_price' => 49.00,
            'yearly_price' => 490.00,
            'max_orders_per_month' => 2500,
            'max_staff_members' => 15,
            'max_devices' => 10,
            'allow_offline_hub' => 1,
            'allow_advanced_branding' => 1,
            'allow_exports' => 1,
            'data_retention_days' => 180,
        ],
        [
            'id' => UUID::v4(),
            'code' => 'premium',
            'name' => 'Enterprise / Premium',
            'monthly_price' => 99.00,
            'yearly_price' => 990.00,
            'max_orders_per_month' => 0, // unlimited
            'max_staff_members' => 50,
            'max_devices' => 30,
            'allow_offline_hub' => 1,
            'allow_advanced_branding' => 1,
            'allow_exports' => 1,
            'data_retention_days' => 365,
        ],
    ];

    $planMap = [];
    $stmtPlanCheck = $pdo->prepare("SELECT id FROM subscription_plans WHERE code = ?");
    $stmtPlanInsert = $pdo->prepare("INSERT INTO subscription_plans 
        (id, code, name, monthly_price, yearly_price, max_orders_per_month, max_staff_members, max_devices, allow_offline_hub, allow_advanced_branding, allow_exports, data_retention_days, is_active, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");

    foreach ($plans as $p) {
        $stmtPlanCheck->execute([$p['code']]);
        $existing = $stmtPlanCheck->fetchColumn();
        if ($existing) {
            $planMap[$p['code']] = $existing;
        } else {
            $stmtPlanInsert->execute([
                $p['id'], $p['code'], $p['name'], $p['monthly_price'], $p['yearly_price'],
                $p['max_orders_per_month'], $p['max_staff_members'], $p['max_devices'],
                $p['allow_offline_hub'], $p['allow_advanced_branding'], $p['allow_exports'],
                $p['data_retention_days']
            ]);
            $planMap[$p['code']] = $p['id'];
        }
    }
    echo "Subscription plans seeded.\n";

    // 3. Platform Settings
    $settings = [
        ['setting_key' => 'platform_name', 'setting_value' => 'QR Order Pager SaaS', 'description' => 'Application Name'],
        ['setting_key' => 'trial_days', 'setting_value' => '14', 'description' => 'Default free trial period in days'],
        ['setting_key' => 'support_email', 'setting_value' => 'support@qrpager.io', 'description' => 'Platform Support Contact'],
        ['setting_key' => 'default_currency', 'setting_value' => 'USD', 'description' => 'Default billing currency'],
        ['setting_key' => 'vapid_public_key', 'setting_value' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', 'description' => 'VAPID Public Key for Web Push'],
        ['setting_key' => 'vapid_private_key', 'setting_value' => 'UUx2Vy1t-rA8k_FwY7uXq7y65Z5aQx7w9rT3vP1sN8m', 'description' => 'VAPID Private Key for Web Push'],
        ['setting_key' => 'vapid_subject', 'setting_value' => 'mailto:admin@qrpager.io', 'description' => 'VAPID Subject / Contact'],
    ];

    $stmtSet = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, description, updated_at) 
        VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    foreach ($settings as $s) {
        $stmtSet->execute([$s['setting_key'], $s['setting_value'], $s['description']]);
    }
    echo "Platform settings seeded.\n";

    // 4. Platform Super Admin
    $superAdminEmail = 'admin@qrpager.io';
    $stmtUserCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmtUserCheck->execute([$superAdminEmail]);
    if (!$stmtUserCheck->fetchColumn()) {
        $pdo->prepare("INSERT INTO users (id, business_id, role_id, name, email, password_hash, is_active, created_at, updated_at) 
            VALUES (?, NULL, ?, 'Platform Admin', ?, ?, 1, NOW(), NOW())")
            ->execute([
                UUID::v4(),
                $roleMap['super_admin'],
                $superAdminEmail,
                password_hash('Password123!', PASSWORD_BCRYPT)
            ]);
        echo "Super Admin account created: {$superAdminEmail} / Password123!\n";
    }

    // 5. Sample Business Tenant
    $bizSlug = 'sunrise-cafe';
    $stmtBizCheck = $pdo->prepare("SELECT id FROM businesses WHERE slug = ?");
    $stmtBizCheck->execute([$bizSlug]);
    $bizId = $stmtBizCheck->fetchColumn();

    if (!$bizId) {
        $bizId = UUID::v4();
        $trialEnd = date('Y-m-d H:i:s', strtotime('+14 days'));
        
        $pdo->prepare("INSERT INTO businesses (id, name, slug, email, phone, default_language, status, trial_ends_at, created_at, updated_at) 
            VALUES (?, 'Sunrise Café & Bistro', ?, 'owner@sunrisecafe.com', '+1 (555) 234-5678', 'en', 'trial', ?, NOW(), NOW())")
            ->execute([$bizId, $bizSlug, $trialEnd]);

        // Settings
        $pdo->prepare("INSERT INTO business_settings 
            (id, business_id, currency_symbol, order_number_prefix, next_order_number, qr_expiration_grace_minutes, estimated_prep_time_minutes, enable_offline_hub, hub_secret_key, notification_ready_message_en, notification_ready_message_ar, notification_ready_message_ku, created_at, updated_at)
            VALUES (?, ?, '$', 'ORD-', 106, 60, 12, 1, ?, 'Your order #{order_number} is READY! Please pick it up at the counter.', 'طلبك رقم {order_number} جاهز! يرجى الاستلام من المنصة.', 'داواکاریەکەت ژمارە {order_number} ئامادەیە! تکایە لە کاونتەر وەریگرە.', NOW(), NOW())")
            ->execute([
                UUID::v4(),
                $bizId,
                bin2hex(random_bytes(16))
            ]);

        // Branding
        $pdo->prepare("INSERT INTO business_branding 
            (id, business_id, primary_color, accent_color, background_color, logo_url, tagline, created_at, updated_at)
            VALUES (?, ?, '#0284c7', '#f59e0b', '#f8fafc', '/assets/img/logo.svg', 'Fresh Artisan Coffee & Gourmet Delights', NOW(), NOW())")
            ->execute([UUID::v4(), $bizId]);

        // Subscription (Trial Starter)
        $pdo->prepare("INSERT INTO subscriptions 
            (id, business_id, plan_id, billing_cycle, status, starts_at, ends_at, trial_ends_at, created_at, updated_at)
            VALUES (?, ?, ?, 'monthly', 'trial', NOW(), ?, ?, NOW(), NOW())")
            ->execute([UUID::v4(), $bizId, $planMap['starter'], $trialEnd, $trialEnd]);

        // Owner Account
        $pdo->prepare("INSERT INTO users (id, business_id, role_id, name, email, password_hash, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 'John Sunrise (Owner)', 'owner@sunrisecafe.com', ?, 1, NOW(), NOW())")
            ->execute([UUID::v4(), $bizId, $roleMap['owner'], password_hash('Password123!', PASSWORD_BCRYPT)]);

        // Cashier Account
        $cashierId = UUID::v4();
        $pdo->prepare("INSERT INTO users (id, business_id, role_id, name, email, password_hash, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 'Sarah Cashier', 'cashier@sunrisecafe.com', ?, 1, NOW(), NOW())")
            ->execute([$cashierId, $bizId, $roleMap['cashier'], password_hash('Password123!', PASSWORD_BCRYPT)]);

        // Kitchen Account
        $kitchenId = UUID::v4();
        $pdo->prepare("INSERT INTO users (id, business_id, role_id, name, email, password_hash, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 'Chef Marco', 'kitchen@sunrisecafe.com', ?, 1, NOW(), NOW())")
            ->execute([$kitchenId, $bizId, $roleMap['kitchen'], password_hash('Password123!', PASSWORD_BCRYPT)]);

        // Categories & Products
        $categories = [
            ['id' => UUID::v4(), 'name' => 'Hot Beverages', 'order' => 1],
            ['id' => UUID::v4(), 'name' => 'Cold Drinks & Shakes', 'order' => 2],
            ['id' => UUID::v4(), 'name' => 'Bakery & Pastries', 'order' => 3],
            ['id' => UUID::v4(), 'name' => 'Gourmet Sandwiches', 'order' => 4],
        ];

        $stmtCat = $pdo->prepare("INSERT INTO product_categories (id, business_id, name, display_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
        foreach ($categories as $cat) {
            $stmtCat->execute([$cat['id'], $bizId, $cat['name'], $cat['order']]);
        }

        $products = [
            ['id' => UUID::v4(), 'cat' => $categories[0]['id'], 'name' => 'Caramel Macchiato', 'desc' => 'Espresso with steamed milk, vanilla syrup and caramel drizzle', 'price' => 4.75],
            ['id' => UUID::v4(), 'cat' => $categories[0]['id'], 'name' => 'Spanish Latte', 'desc' => 'Rich espresso with sweetened condensed milk', 'price' => 5.25],
            ['id' => UUID::v4(), 'cat' => $categories[0]['id'], 'name' => 'Double Espresso', 'desc' => 'Intense freshly roasted double shot', 'price' => 3.00],
            ['id' => UUID::v4(), 'cat' => $categories[1]['id'], 'name' => 'Iced Matcha Green Tea Latte', 'desc' => 'Ceremonial grade matcha with creamy almond milk over ice', 'price' => 5.50],
            ['id' => UUID::v4(), 'cat' => $categories[1]['id'], 'name' => 'Mango Passion Fruit Smoothie', 'desc' => 'Real mango puree blended with chilled passion fruit', 'price' => 6.00],
            ['id' => UUID::v4(), 'cat' => $categories[2]['id'], 'name' => 'Butter Almond Croissant', 'desc' => 'Golden flaky French croissant topped with toasted almonds', 'price' => 3.95],
            ['id' => UUID::v4(), 'cat' => $categories[2]['id'], 'name' => 'Warm Chocolate Brownie', 'desc' => 'Double fudge chocolate brownie served warm', 'price' => 4.25],
            ['id' => UUID::v4(), 'cat' => $categories[3]['id'], 'name' => 'Smoked Turkey & Swiss Panini', 'desc' => 'Artisan sourdough with honey mustard and melted Swiss cheese', 'price' => 8.50],
            ['id' => UUID::v4(), 'cat' => $categories[3]['id'], 'name' => 'Grilled Chicken Avocado Wrap', 'desc' => 'Marinated chicken breast, fresh Haas avocado, cilantro aioli', 'price' => 9.25],
        ];

        $stmtProd = $pdo->prepare("INSERT INTO products (id, business_id, category_id, name, description, price, is_available, is_active, display_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 0, NOW(), NOW())");
        foreach ($products as $p) {
            $stmtProd->execute([$p['id'], $bizId, $p['cat'], $p['name'], $p['desc'], $p['price']]);
        }

        // Seed initial orders across statuses so Cashier, Kitchen KDS, Customer Track, and Analytics immediately have rich data!
        $orderData = [
            [
                'id' => UUID::v4(),
                'number' => 'ORD-101',
                'token' => 'tk_demo_pnd_' . bin2hex(random_bytes(16)),
                'status' => 'pending',
                'items' => [
                    ['name' => 'Caramel Macchiato', 'price' => 4.75, 'qty' => 1, 'notes' => 'Extra hot'],
                    ['name' => 'Butter Almond Croissant', 'price' => 3.95, 'qty' => 2, 'notes' => 'Warm up please'],
                ],
                'notes' => 'Table 4 / Takeaway pager',
                'created_mins_ago' => 4,
            ],
            [
                'id' => UUID::v4(),
                'number' => 'ORD-102',
                'token' => 'tk_demo_prep_' . bin2hex(random_bytes(16)),
                'status' => 'preparing',
                'items' => [
                    ['name' => 'Smoked Turkey & Swiss Panini', 'price' => 8.50, 'qty' => 2, 'notes' => 'No mustard on one'],
                    ['name' => 'Mango Passion Fruit Smoothie', 'price' => 6.00, 'qty' => 2, 'notes' => 'Less ice'],
                ],
                'notes' => 'Customer waiting near entrance',
                'created_mins_ago' => 11,
            ],
            [
                'id' => UUID::v4(),
                'number' => 'ORD-103',
                'token' => 'tk_demo_rdy_' . bin2hex(random_bytes(16)),
                'status' => 'ready',
                'items' => [
                    ['name' => 'Spanish Latte', 'price' => 5.25, 'qty' => 1, 'notes' => 'Oat milk substitute'],
                    ['name' => 'Warm Chocolate Brownie', 'price' => 4.25, 'qty' => 1, 'notes' => ''],
                ],
                'notes' => 'Pager notified',
                'created_mins_ago' => 16,
            ],
            [
                'id' => UUID::v4(),
                'number' => 'ORD-104',
                'token' => 'tk_demo_col_' . bin2hex(random_bytes(16)),
                'status' => 'collected',
                'items' => [
                    ['name' => 'Grilled Chicken Avocado Wrap', 'price' => 9.25, 'qty' => 1, 'notes' => ''],
                    ['name' => 'Double Espresso', 'price' => 3.00, 'qty' => 1, 'notes' => ''],
                ],
                'notes' => 'Completed smoothly',
                'created_mins_ago' => 38,
            ],
            [
                'id' => UUID::v4(),
                'number' => 'ORD-105',
                'token' => 'tk_demo_can_' . bin2hex(random_bytes(16)),
                'status' => 'cancelled',
                'items' => [
                    ['name' => 'Iced Matcha Green Tea Latte', 'price' => 5.50, 'qty' => 1, 'notes' => ''],
                ],
                'notes' => 'Customer had to leave urgently',
                'created_mins_ago' => 65,
            ]
        ];

        $stmtOrd = $pdo->prepare("INSERT INTO orders 
            (id, business_id, order_number, tracking_token, status, total_amount, customer_notes, staff_notes, created_by_user_id, sync_state, created_at, updated_at, preparing_at, ready_at, collected_at, cancelled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?, ?, ?, ?, ?)");

        $stmtItem = $pdo->prepare("INSERT INTO order_items (id, order_id, product_name_snapshot, unit_price_snapshot, quantity, item_notes, subtotal, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $stmtHist = $pdo->prepare("INSERT INTO order_status_history (id, order_id, previous_status, new_status, changed_by_user_id, source, transition_time, created_at)
            VALUES (?, ?, ?, ?, ?, 'cloud_web', ?, ?)");

        foreach ($orderData as $od) {
            $createdAt = date('Y-m-d H:i:s', strtotime("-{$od['created_mins_ago']} minutes"));
            $prepAt = in_array($od['status'], ['preparing', 'ready', 'collected']) ? date('Y-m-d H:i:s', strtotime("-{$od['created_mins_ago']} minutes + 2 minutes")) : null;
            $rdyAt = in_array($od['status'], ['ready', 'collected']) ? date('Y-m-d H:i:s', strtotime("-{$od['created_mins_ago']} minutes + 10 minutes")) : null;
            $colAt = ($od['status'] === 'collected') ? date('Y-m-d H:i:s', strtotime("-{$od['created_mins_ago']} minutes + 15 minutes")) : null;
            $canAt = ($od['status'] === 'cancelled') ? date('Y-m-d H:i:s', strtotime("-{$od['created_mins_ago']} minutes + 5 minutes")) : null;

            $total = 0;
            foreach ($od['items'] as $it) {
                $total += $it['price'] * $it['qty'];
            }

            $stmtOrd->execute([
                $od['id'], $bizId, $od['number'], $od['token'], $od['status'], $total,
                $od['notes'], 'Initial demo order', $cashierId,
                $createdAt, $createdAt, $prepAt, $rdyAt, $colAt, $canAt
            ]);

            foreach ($od['items'] as $it) {
                $subtotal = $it['price'] * $it['qty'];
                $stmtItem->execute([
                    UUID::v4(), $od['id'], $it['name'], $it['price'], $it['qty'], $it['notes'], $subtotal, $createdAt
                ]);
            }

            // Status history
            $stmtHist->execute([UUID::v4(), $od['id'], null, 'pending', $cashierId, $createdAt, $createdAt]);
            if ($prepAt) {
                $stmtHist->execute([UUID::v4(), $od['id'], 'pending', 'preparing', $kitchenId, $prepAt, $prepAt]);
            }
            if ($rdyAt) {
                $stmtHist->execute([UUID::v4(), $od['id'], 'preparing', 'ready', $kitchenId, $rdyAt, $rdyAt]);
            }
            if ($colAt) {
                $stmtHist->execute([UUID::v4(), $od['id'], 'ready', 'collected', $cashierId, $colAt, $colAt]);
            }
            if ($canAt) {
                $stmtHist->execute([UUID::v4(), $od['id'], 'pending', 'cancelled', $cashierId, $canAt, $canAt]);
            }
        }

        echo "Sample business 'Sunrise Cafe & Bistro' seeded with categories, products, and sample orders.\n";
    }

    echo "Database seeding finished successfully!\n";
}

// Run if called directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $dbConfig = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    seedDatabase($pdo);
}
