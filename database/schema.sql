-- Multi-Tenant QR Order Pager SaaS Platform Schema
-- MySQL 8.0+ / MariaDB 10.4+ compatible

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Businesses (Tenants)
CREATE TABLE IF NOT EXISTS `businesses` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(50) NULL,
    `default_language` VARCHAR(10) NOT NULL DEFAULT 'en',
    `status` ENUM('trial', 'active', 'suspended', 'cancelled') NOT NULL DEFAULT 'trial',
    `trial_ends_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_biz_status` (`status`),
    INDEX `idx_biz_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Business Settings
CREATE TABLE IF NOT EXISTS `business_settings` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL UNIQUE,
    `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '$',
    `order_number_prefix` VARCHAR(10) NOT NULL DEFAULT 'ORD-',
    `next_order_number` INT NOT NULL DEFAULT 1,
    `qr_expiration_grace_minutes` INT NOT NULL DEFAULT 60,
    `currency_iqd_rate` DECIMAL(12,4) NOT NULL DEFAULT 1460,
    `estimated_prep_time_minutes` INT NOT NULL DEFAULT 15,
    `enable_offline_hub` TINYINT(1) NOT NULL DEFAULT 1,
    `hub_secret_key` VARCHAR(64) NOT NULL,
    `notification_ready_message_en` TEXT NULL,
    `notification_ready_message_ar` TEXT NULL,
    `notification_ready_message_ku` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    CONSTRAINT `fk_bs_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Business Branding
CREATE TABLE IF NOT EXISTS `business_branding` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL UNIQUE,
    `primary_color` VARCHAR(20) NOT NULL DEFAULT '#2563eb',
    `accent_color` VARCHAR(20) NOT NULL DEFAULT '#f59e0b',
    `background_color` VARCHAR(20) NOT NULL DEFAULT '#f8fafc',
    `logo_url` VARCHAR(255) NULL,
    `tagline` VARCHAR(255) NULL,
    `custom_header_html` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    CONSTRAINT `fk_bb_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Subscription Plans
CREATE TABLE IF NOT EXISTS `subscription_plans` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `yearly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `max_orders_per_month` INT NOT NULL DEFAULT 500,
    `max_staff_members` INT NOT NULL DEFAULT 5,
    `max_devices` INT NOT NULL DEFAULT 3,
    `allow_offline_hub` TINYINT(1) NOT NULL DEFAULT 1,
    `allow_advanced_branding` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_exports` TINYINT(1) NOT NULL DEFAULT 0,
    `data_retention_days` INT NOT NULL DEFAULT 90,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Subscriptions
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `plan_id` VARCHAR(36) NOT NULL,
    `billing_cycle` ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
    `status` ENUM('trial', 'active', 'grace_period', 'suspended', 'cancelled') NOT NULL DEFAULT 'trial',
    `starts_at` DATETIME NOT NULL,
    `ends_at` DATETIME NOT NULL,
    `trial_ends_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_sub_biz` (`business_id`),
    INDEX `idx_sub_status` (`status`),
    CONSTRAINT `fk_sub_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Subscription Payments (Manual Verification)
CREATE TABLE IF NOT EXISTS `subscription_payments` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `subscription_id` VARCHAR(36) NOT NULL,
    `plan_id` VARCHAR(36) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'manual_bank_transfer',
    `reference_note` VARCHAR(255) NULL,
    `status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    `verified_by_user_id` VARCHAR(36) NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_pay_biz` (`business_id`),
    INDEX `idx_pay_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Roles & Permissions
CREATE TABLE IF NOT EXISTS `roles` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` VARCHAR(36) NOT NULL,
    `permission_id` VARCHAR(36) NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Users
CREATE TABLE IF NOT EXISTS `users` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NULL, -- NULL for platform super_admin
    `role_id` VARCHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_usr_biz` (`business_id`),
    INDEX `idx_usr_role` (`role_id`),
    CONSTRAINT `fk_usr_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_usr_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Product Categories
CREATE TABLE IF NOT EXISTS `product_categories` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_cat_biz` (`business_id`),
    CONSTRAINT `fk_cat_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Products
CREATE TABLE IF NOT EXISTS `products` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `category_id` VARCHAR(36) NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `image_url` VARCHAR(255) NULL,
    `is_available` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_prd_biz` (`business_id`),
    INDEX `idx_prd_cat` (`category_id`),
    CONSTRAINT `fk_prd_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_prd_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Orders
CREATE TABLE IF NOT EXISTS `orders` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `order_number` VARCHAR(50) NOT NULL,
    `tracking_token` VARCHAR(64) NOT NULL UNIQUE,
    `status` ENUM('pending', 'preparing', 'ready', 'collected', 'cancelled') NOT NULL DEFAULT 'pending',
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `customer_notes` TEXT NULL,
    `staff_notes` TEXT NULL,
    `created_by_user_id` VARCHAR(36) NULL,
    `assigned_staff_id` VARCHAR(36) NULL,
    `is_offline_created` TINYINT(1) NOT NULL DEFAULT 0,
    `sync_state` ENUM('synced', 'pending_sync', 'conflict') NOT NULL DEFAULT 'synced',
    `local_device_id` VARCHAR(64) NULL,
    `idempotency_key` VARCHAR(128) NULL UNIQUE,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    `preparing_at` DATETIME NULL,
    `ready_at` DATETIME NULL,
    `collected_at` DATETIME NULL,
    `cancelled_at` DATETIME NULL,
    INDEX `idx_ord_biz` (`business_id`),
    INDEX `idx_ord_status` (`status`),
    INDEX `idx_ord_num` (`order_number`),
    INDEX `idx_ord_token` (`tracking_token`),
    INDEX `idx_ord_created` (`created_at`),
    CONSTRAINT `fk_ord_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Order Items (Preserving snapshots of product name and price)
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `order_id` VARCHAR(36) NOT NULL,
    `product_id` VARCHAR(36) NULL,
    `product_name_snapshot` VARCHAR(150) NOT NULL,
    `unit_price_snapshot` DECIMAL(10,2) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `item_notes` TEXT NULL,
    `subtotal` DECIMAL(10,2) NOT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_oi_order` (`order_id`),
    CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Order Status History (Audit trail of every transition)
CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `order_id` VARCHAR(36) NOT NULL,
    `previous_status` VARCHAR(30) NULL,
    `new_status` VARCHAR(30) NOT NULL,
    `changed_by_user_id` VARCHAR(36) NULL,
    `source` ENUM('cloud_web', 'local_hub', 'cashier_offline', 'kitchen_terminal', 'system') NOT NULL DEFAULT 'cloud_web',
    `device_info` VARCHAR(255) NULL,
    `is_offline_transition` TINYINT(1) NOT NULL DEFAULT 0,
    `transition_time` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_osh_order` (`order_id`),
    CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Push Subscriptions (Web Push API)
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `order_id` VARCHAR(36) NOT NULL,
    `endpoint` VARCHAR(1000) NOT NULL,
    `p256dh_key` VARCHAR(255) NOT NULL,
    `auth_token` VARCHAR(255) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_ps_order` (`order_id`),
    INDEX `idx_ps_biz` (`business_id`),
    CONSTRAINT `fk_ps_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Notification Jobs (Idempotent Ready notification queue)
CREATE TABLE IF NOT EXISTS `notification_jobs` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `order_id` VARCHAR(36) NOT NULL,
    `subscription_id` VARCHAR(36) NOT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL UNIQUE,
    `status` ENUM('pending', 'processing', 'sent', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    `attempts` INT NOT NULL DEFAULT 0,
    `payload_json` TEXT NOT NULL,
    `error_message` TEXT NULL,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_nj_status` (`status`),
    INDEX `idx_nj_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Notification Logs
CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `order_id` VARCHAR(36) NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `status_code` INT NULL,
    `response_body` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_nl_biz` (`business_id`),
    INDEX `idx_nl_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Local Devices (Registered for Local Business Hub / LAN)
CREATE TABLE IF NOT EXISTS `local_devices` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `device_uuid` VARCHAR(64) NOT NULL UNIQUE,
    `device_name` VARCHAR(100) NOT NULL,
    `device_type` ENUM('hub_server', 'cashier_pos', 'kitchen_kds', 'backup_terminal') NOT NULL,
    `auth_token_hash` VARCHAR(255) NOT NULL,
    `last_ip_address` VARCHAR(50) NULL,
    `last_heartbeat_at` DATETIME NULL,
    `is_authorized` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_ld_biz` (`business_id`),
    CONSTRAINT `fk_ld_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Sync Batches (Edge to Cloud synchronization)
CREATE TABLE IF NOT EXISTS `sync_batches` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NOT NULL,
    `device_uuid` VARCHAR(64) NOT NULL,
    `batch_id` VARCHAR(64) NOT NULL UNIQUE,
    `orders_count` INT NOT NULL DEFAULT 0,
    `status_updates_count` INT NOT NULL DEFAULT 0,
    `conflicts_count` INT NOT NULL DEFAULT 0,
    `status` ENUM('pending', 'completed', 'partially_failed', 'failed') NOT NULL DEFAULT 'completed',
    `details_json` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_sb_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Sync Events (Itemized records of sync processing)
CREATE TABLE IF NOT EXISTS `sync_events` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `sync_batch_id` VARCHAR(36) NOT NULL,
    `business_id` VARCHAR(36) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(64) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `resolution` ENUM('applied', 'skipped_duplicate', 'conflict_cloud_wins', 'error') NOT NULL,
    `message` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_se_batch` (`sync_batch_id`),
    INDEX `idx_se_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Audit Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `business_id` VARCHAR(36) NULL,
    `user_id` VARCHAR(36) NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `ip_address` VARCHAR(50) NULL,
    `user_agent` VARCHAR(255) NULL,
    `context_json` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_al_biz` (`business_id`),
    INDEX `idx_al_event` (`event_type`),
    INDEX `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Platform Settings
CREATE TABLE IF NOT EXISTS `platform_settings` (
    `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT NOT NULL,
    `description` VARCHAR(255) NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
