-- QR Order Pager SaaS Platform Automated Database Backup
-- Created: 2026-11-04 18:59:16
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) DEFAULT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `context_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_al_biz` (`business_id`),
  KEY `idx_al_event` (`event_type`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `business_branding`;
CREATE TABLE `business_branding` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `primary_color` varchar(20) NOT NULL DEFAULT '#2563eb',
  `accent_color` varchar(20) NOT NULL DEFAULT '#f59e0b',
  `background_color` varchar(20) NOT NULL DEFAULT '#f8fafc',
  `logo_url` varchar(255) DEFAULT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `custom_header_html` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_id` (`business_id`),
  CONSTRAINT `fk_bb_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `business_branding` (`id`, `business_id`, `primary_color`, `accent_color`, `background_color`, `logo_url`, `tagline`, `custom_header_html`, `created_at`, `updated_at`) VALUES ('4aef13d4-4644-48f8-b695-909440e10198', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '#0284c7', '#f59e0b', '#f8fafc', '/assets/img/logo.svg', 'Fresh Artisan Coffee & Gourmet Delights', NULL, '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `business_settings`;
CREATE TABLE `business_settings` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `currency_symbol` varchar(10) NOT NULL DEFAULT '$',
  `order_number_prefix` varchar(10) NOT NULL DEFAULT 'ORD-',
  `next_order_number` int(11) NOT NULL DEFAULT 1,
  `qr_expiration_grace_minutes` int(11) NOT NULL DEFAULT 60,
  `estimated_prep_time_minutes` int(11) NOT NULL DEFAULT 15,
  `enable_offline_hub` tinyint(1) NOT NULL DEFAULT 1,
  `hub_secret_key` varchar(64) NOT NULL,
  `notification_ready_message_en` text DEFAULT NULL,
  `notification_ready_message_ar` text DEFAULT NULL,
  `notification_ready_message_ku` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_id` (`business_id`),
  CONSTRAINT `fk_bs_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `business_settings` (`id`, `business_id`, `currency_symbol`, `order_number_prefix`, `next_order_number`, `qr_expiration_grace_minutes`, `estimated_prep_time_minutes`, `enable_offline_hub`, `hub_secret_key`, `notification_ready_message_en`, `notification_ready_message_ar`, `notification_ready_message_ku`, `created_at`, `updated_at`) VALUES ('3bd57614-d98d-4e97-a8c7-dd55e42c82a5', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '$', 'ORD-', '106', '60', '12', '1', 'cd3421545704092564d9c968dd0db59c', 'Your order #{order_number} is READY! Please pick it up at the counter.', 'طلبك رقم {order_number} جاهز! يرجى الاستلام من المنصة.', 'داواکاریەکەت ژمارە {order_number} ئامادەیە! تکایە لە کاونتەر وەریگرە.', '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `businesses`;
CREATE TABLE `businesses` (
  `id` varchar(36) NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `default_language` varchar(10) NOT NULL DEFAULT 'en',
  `status` enum('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `trial_ends_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_biz_status` (`status`),
  KEY `idx_biz_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `businesses` (`id`, `name`, `slug`, `email`, `phone`, `default_language`, `status`, `trial_ends_at`, `created_at`, `updated_at`) VALUES ('c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'Sunrise Café & Bistro', 'sunrise-cafe', 'owner@sunrisecafe.com', '+1 (555) 234-5678', 'en', 'trial', '2026-11-18 18:59:11', '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `local_devices`;
CREATE TABLE `local_devices` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `device_uuid` varchar(64) NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `device_type` enum('hub_server','cashier_pos','kitchen_kds','backup_terminal') NOT NULL,
  `auth_token_hash` varchar(255) NOT NULL,
  `last_ip_address` varchar(50) DEFAULT NULL,
  `last_heartbeat_at` datetime DEFAULT NULL,
  `is_authorized` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_uuid` (`device_uuid`),
  KEY `idx_ld_biz` (`business_id`),
  CONSTRAINT `fk_ld_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_jobs`;
CREATE TABLE `notification_jobs` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `order_id` varchar(36) NOT NULL,
  `subscription_id` varchar(36) NOT NULL,
  `idempotency_key` varchar(128) NOT NULL,
  `status` enum('pending','processing','sent','failed','expired') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `payload_json` text NOT NULL,
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_nj_status` (`status`),
  KEY `idx_nj_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_logs`;
CREATE TABLE `notification_logs` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `order_id` varchar(36) NOT NULL,
  `status` varchar(30) NOT NULL,
  `status_code` int(11) DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nl_biz` (`business_id`),
  KEY `idx_nl_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` varchar(36) NOT NULL,
  `order_id` varchar(36) NOT NULL,
  `product_id` varchar(36) DEFAULT NULL,
  `product_name_snapshot` varchar(150) NOT NULL,
  `unit_price_snapshot` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `item_notes` text DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('1534c6ad-a46f-43b2-a1a6-9a3c00789323', '7ec22544-b39a-43ec-9fc4-d6901b558ad0', NULL, 'Mango Passion Fruit Smoothie', '6.00', '2', 'Less ice', '12.00', '2026-11-04 18:48:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('45338c12-27bf-4b8b-afbb-56499523dca1', '1d30be9f-10bf-4f0f-b4e9-976748b01f5c', NULL, 'Warm Chocolate Brownie', '4.25', '1', '', '4.25', '2026-11-04 18:43:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('4720d28e-611b-4476-bb03-a51fb63a3395', '89d7bf81-f754-4135-944a-828a3cbc8522', NULL, 'Butter Almond Croissant', '3.95', '2', 'Warm up please', '7.90', '2026-11-04 18:55:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('60dc3e36-26da-4d34-afbe-75468073671e', '7ec22544-b39a-43ec-9fc4-d6901b558ad0', NULL, 'Smoked Turkey & Swiss Panini', '8.50', '2', 'No mustard on one', '17.00', '2026-11-04 18:48:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('6b4b1f4a-b9d3-414e-a1d6-59659eff8cb7', '075d3570-072d-4099-9fd7-ce19f1b64170', NULL, 'Grilled Chicken Avocado Wrap', '9.25', '1', '', '9.25', '2026-11-04 18:21:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('bfc61772-b68f-484d-80a9-cf9311d4d8a5', '075d3570-072d-4099-9fd7-ce19f1b64170', NULL, 'Double Espresso', '3.00', '1', '', '3.00', '2026-11-04 18:21:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('c235cb26-3c9f-4d9c-ad59-7a9c08494c57', '89d7bf81-f754-4135-944a-828a3cbc8522', NULL, 'Caramel Macchiato', '4.75', '1', 'Extra hot', '4.75', '2026-11-04 18:55:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('e1a17886-5e13-4647-848e-e7589037f3c9', 'ba726204-1bd5-4b01-b24a-131661cfb1d7', NULL, 'Iced Matcha Green Tea Latte', '5.50', '1', '', '5.50', '2026-11-04 17:54:11');
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name_snapshot`, `unit_price_snapshot`, `quantity`, `item_notes`, `subtotal`, `created_at`) VALUES ('f9ebef04-9cf8-4746-ba37-6dcbb0f535da', '1d30be9f-10bf-4f0f-b4e9-976748b01f5c', NULL, 'Spanish Latte', '5.25', '1', 'Oat milk substitute', '5.25', '2026-11-04 18:43:11');

DROP TABLE IF EXISTS `order_status_history`;
CREATE TABLE `order_status_history` (
  `id` varchar(36) NOT NULL,
  `order_id` varchar(36) NOT NULL,
  `previous_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by_user_id` varchar(36) DEFAULT NULL,
  `source` enum('cloud_web','local_hub','cashier_offline','kitchen_terminal','system') NOT NULL DEFAULT 'cloud_web',
  `device_info` varchar(255) DEFAULT NULL,
  `is_offline_transition` tinyint(1) NOT NULL DEFAULT 0,
  `transition_time` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_osh_order` (`order_id`),
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('1183728a-b951-499d-83c1-71a5120071f2', '1d30be9f-10bf-4f0f-b4e9-976748b01f5c', 'preparing', 'ready', 'ea11f0e8-8b0c-4eb8-9e10-7427f03076ee', 'cloud_web', NULL, '0', '2026-11-04 18:53:11', '2026-11-04 18:53:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('303b8baa-9d04-4160-b8ef-1879346bd5b7', '1d30be9f-10bf-4f0f-b4e9-976748b01f5c', NULL, 'pending', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 18:43:11', '2026-11-04 18:43:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('3068b527-911f-4fe1-b28b-d24885863ef4', '7ec22544-b39a-43ec-9fc4-d6901b558ad0', 'pending', 'preparing', 'ea11f0e8-8b0c-4eb8-9e10-7427f03076ee', 'cloud_web', NULL, '0', '2026-11-04 18:50:11', '2026-11-04 18:50:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('3761724f-c769-4263-a2e8-4e5a87c1cc27', '075d3570-072d-4099-9fd7-ce19f1b64170', 'pending', 'preparing', 'ea11f0e8-8b0c-4eb8-9e10-7427f03076ee', 'cloud_web', NULL, '0', '2026-11-04 18:23:11', '2026-11-04 18:23:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('5400f6e7-ec5b-45e0-8159-a1332912df3b', 'ba726204-1bd5-4b01-b24a-131661cfb1d7', 'pending', 'cancelled', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 17:59:11', '2026-11-04 17:59:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('6529e730-2e0d-4075-90f6-4af673602e78', 'ba726204-1bd5-4b01-b24a-131661cfb1d7', NULL, 'pending', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 17:54:11', '2026-11-04 17:54:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('873036f4-674f-41be-98b6-8592403cfc64', '7ec22544-b39a-43ec-9fc4-d6901b558ad0', NULL, 'pending', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 18:48:11', '2026-11-04 18:48:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('92dc0231-723e-4114-82f7-39a240897274', '89d7bf81-f754-4135-944a-828a3cbc8522', NULL, 'pending', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 18:55:11', '2026-11-04 18:55:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('a220fa47-d443-4345-9e44-a3f862b46841', '075d3570-072d-4099-9fd7-ce19f1b64170', 'ready', 'collected', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 18:36:11', '2026-11-04 18:36:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('b967df86-b7ef-4ada-b726-851a178793ff', '075d3570-072d-4099-9fd7-ce19f1b64170', 'preparing', 'ready', 'ea11f0e8-8b0c-4eb8-9e10-7427f03076ee', 'cloud_web', NULL, '0', '2026-11-04 18:31:11', '2026-11-04 18:31:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('c0cfd253-36cd-4c56-9d3c-00dc289ffe20', '1d30be9f-10bf-4f0f-b4e9-976748b01f5c', 'pending', 'preparing', 'ea11f0e8-8b0c-4eb8-9e10-7427f03076ee', 'cloud_web', NULL, '0', '2026-11-04 18:45:11', '2026-11-04 18:45:11');
INSERT INTO `order_status_history` (`id`, `order_id`, `previous_status`, `new_status`, `changed_by_user_id`, `source`, `device_info`, `is_offline_transition`, `transition_time`, `created_at`) VALUES ('f29263f0-3c6d-480d-8d68-7e1ad004aafb', '075d3570-072d-4099-9fd7-ce19f1b64170', NULL, 'pending', '057e9c7f-e903-436b-be14-61373310c93f', 'cloud_web', NULL, '0', '2026-11-04 18:21:11', '2026-11-04 18:21:11');

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `tracking_token` varchar(64) NOT NULL,
  `status` enum('pending','preparing','ready','collected','cancelled') NOT NULL DEFAULT 'pending',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `customer_notes` text DEFAULT NULL,
  `staff_notes` text DEFAULT NULL,
  `created_by_user_id` varchar(36) DEFAULT NULL,
  `assigned_staff_id` varchar(36) DEFAULT NULL,
  `is_offline_created` tinyint(1) NOT NULL DEFAULT 0,
  `sync_state` enum('synced','pending_sync','conflict') NOT NULL DEFAULT 'synced',
  `local_device_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `preparing_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `collected_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_token` (`tracking_token`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_ord_biz` (`business_id`),
  KEY `idx_ord_status` (`status`),
  KEY `idx_ord_num` (`order_number`),
  KEY `idx_ord_token` (`tracking_token`),
  KEY `idx_ord_created` (`created_at`),
  CONSTRAINT `fk_ord_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `orders` (`id`, `business_id`, `order_number`, `tracking_token`, `status`, `total_amount`, `customer_notes`, `staff_notes`, `created_by_user_id`, `assigned_staff_id`, `is_offline_created`, `sync_state`, `local_device_id`, `idempotency_key`, `created_at`, `updated_at`, `preparing_at`, `ready_at`, `collected_at`, `cancelled_at`) VALUES ('075d3570-072d-4099-9fd7-ce19f1b64170', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'ORD-104', 'tk_demo_col_db867f2d38b30623fbb5644f8b87583b', 'collected', '12.25', 'Completed smoothly', 'Initial demo order', '057e9c7f-e903-436b-be14-61373310c93f', NULL, '0', 'synced', NULL, NULL, '2026-11-04 18:21:11', '2026-11-04 18:21:11', '2026-11-04 18:23:11', '2026-11-04 18:31:11', '2026-11-04 18:36:11', NULL);
INSERT INTO `orders` (`id`, `business_id`, `order_number`, `tracking_token`, `status`, `total_amount`, `customer_notes`, `staff_notes`, `created_by_user_id`, `assigned_staff_id`, `is_offline_created`, `sync_state`, `local_device_id`, `idempotency_key`, `created_at`, `updated_at`, `preparing_at`, `ready_at`, `collected_at`, `cancelled_at`) VALUES ('1d30be9f-10bf-4f0f-b4e9-976748b01f5c', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'ORD-103', 'tk_demo_rdy_1df02107ae4f07880074ae2ef99fb9f9', 'ready', '9.50', 'Pager notified', 'Initial demo order', '057e9c7f-e903-436b-be14-61373310c93f', NULL, '0', 'synced', NULL, NULL, '2026-11-04 18:43:11', '2026-11-04 18:43:11', '2026-11-04 18:45:11', '2026-11-04 18:53:11', NULL, NULL);
INSERT INTO `orders` (`id`, `business_id`, `order_number`, `tracking_token`, `status`, `total_amount`, `customer_notes`, `staff_notes`, `created_by_user_id`, `assigned_staff_id`, `is_offline_created`, `sync_state`, `local_device_id`, `idempotency_key`, `created_at`, `updated_at`, `preparing_at`, `ready_at`, `collected_at`, `cancelled_at`) VALUES ('7ec22544-b39a-43ec-9fc4-d6901b558ad0', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'ORD-102', 'tk_demo_prep_04632cffc905956be4f76e05cb73bf44', 'preparing', '29.00', 'Customer waiting near entrance', 'Initial demo order', '057e9c7f-e903-436b-be14-61373310c93f', NULL, '0', 'synced', NULL, NULL, '2026-11-04 18:48:11', '2026-11-04 18:48:11', '2026-11-04 18:50:11', NULL, NULL, NULL);
INSERT INTO `orders` (`id`, `business_id`, `order_number`, `tracking_token`, `status`, `total_amount`, `customer_notes`, `staff_notes`, `created_by_user_id`, `assigned_staff_id`, `is_offline_created`, `sync_state`, `local_device_id`, `idempotency_key`, `created_at`, `updated_at`, `preparing_at`, `ready_at`, `collected_at`, `cancelled_at`) VALUES ('89d7bf81-f754-4135-944a-828a3cbc8522', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'ORD-101', 'tk_demo_pnd_8161dd712191b439d942f20aea626cb8', 'pending', '12.65', 'Table 4 / Takeaway pager', 'Initial demo order', '057e9c7f-e903-436b-be14-61373310c93f', NULL, '0', 'synced', NULL, NULL, '2026-11-04 18:55:11', '2026-11-04 18:55:11', NULL, NULL, NULL, NULL);
INSERT INTO `orders` (`id`, `business_id`, `order_number`, `tracking_token`, `status`, `total_amount`, `customer_notes`, `staff_notes`, `created_by_user_id`, `assigned_staff_id`, `is_offline_created`, `sync_state`, `local_device_id`, `idempotency_key`, `created_at`, `updated_at`, `preparing_at`, `ready_at`, `collected_at`, `cancelled_at`) VALUES ('ba726204-1bd5-4b01-b24a-131661cfb1d7', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'ORD-105', 'tk_demo_can_2a1599a6f995c6f5a6a31b3ba7c4d187', 'cancelled', '5.50', 'Customer had to leave urgently', 'Initial demo order', '057e9c7f-e903-436b-be14-61373310c93f', NULL, '0', 'synced', NULL, NULL, '2026-11-04 17:54:11', '2026-11-04 17:54:11', NULL, NULL, NULL, '2026-11-04 17:59:11');

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` varchar(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `platform_settings`;
CREATE TABLE `platform_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('default_currency', 'USD', 'Default billing currency', '2026-11-04 20:59:11');
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('platform_name', 'QR Order Pager SaaS', 'Application Name', '2026-11-04 20:59:11');
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('support_email', 'support@qrpager.io', 'Platform Support Contact', '2026-11-04 20:59:11');
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('trial_days', '14', 'Default free trial period in days', '2026-11-04 20:59:11');
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('vapid_private_key', 'UUx2Vy1t-rA8k_FwY7uXq7y65Z5aQx7w9rT3vP1sN8m', 'VAPID Private Key for Web Push', '2026-11-04 20:59:11');
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('vapid_public_key', 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', 'VAPID Public Key for Web Push', '2026-11-04 20:59:11');
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES ('vapid_subject', 'mailto:admin@qrpager.io', 'VAPID Subject / Contact', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE `product_categories` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cat_biz` (`business_id`),
  CONSTRAINT `fk_cat_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_categories` (`id`, `business_id`, `name`, `display_order`, `is_active`, `created_at`, `updated_at`) VALUES ('1f2fe90c-63d1-453a-92cb-3b2b7dc662f6', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'Hot Beverages', '1', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `product_categories` (`id`, `business_id`, `name`, `display_order`, `is_active`, `created_at`, `updated_at`) VALUES ('2c4acc3d-b531-4b3b-b9f9-b64f36dc2aa9', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'Cold Drinks & Shakes', '2', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `product_categories` (`id`, `business_id`, `name`, `display_order`, `is_active`, `created_at`, `updated_at`) VALUES ('92842e8f-6bd0-4d04-b267-137e17cbb324', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'Bakery & Pastries', '3', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `product_categories` (`id`, `business_id`, `name`, `display_order`, `is_active`, `created_at`, `updated_at`) VALUES ('e7cb1522-13da-4ed4-9447-aa1b45c1d186', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'Gourmet Sandwiches', '4', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `category_id` varchar(36) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image_url` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prd_biz` (`business_id`),
  KEY `idx_prd_cat` (`category_id`),
  CONSTRAINT `fk_prd_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prd_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('26101840-3485-4876-a9d3-1cb956a4d21e', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '1f2fe90c-63d1-453a-92cb-3b2b7dc662f6', 'Spanish Latte', 'Rich espresso with sweetened condensed milk', '5.25', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('46e4e4da-92a3-4819-aecb-4b1c5f2bcc5b', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '92842e8f-6bd0-4d04-b267-137e17cbb324', 'Warm Chocolate Brownie', 'Double fudge chocolate brownie served warm', '4.25', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('702577cd-5d18-41f1-bf33-e2fe15132581', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '1f2fe90c-63d1-453a-92cb-3b2b7dc662f6', 'Caramel Macchiato', 'Espresso with steamed milk, vanilla syrup and caramel drizzle', '4.75', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('910330d2-82e9-49ff-aa3e-4d22be0cb803', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '2c4acc3d-b531-4b3b-b9f9-b64f36dc2aa9', 'Mango Passion Fruit Smoothie', 'Real mango puree blended with chilled passion fruit', '6.00', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('976c403d-62e8-43ca-bf44-c9576e2009e7', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '2c4acc3d-b531-4b3b-b9f9-b64f36dc2aa9', 'Iced Matcha Green Tea Latte', 'Ceremonial grade matcha with creamy almond milk over ice', '5.50', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('ae7a9b4d-fafa-4ce9-a3e4-81f79d561fc6', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '1f2fe90c-63d1-453a-92cb-3b2b7dc662f6', 'Double Espresso', 'Intense freshly roasted double shot', '3.00', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('cdfbbb57-b208-47ce-b29d-6b3e5d819212', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'e7cb1522-13da-4ed4-9447-aa1b45c1d186', 'Grilled Chicken Avocado Wrap', 'Marinated chicken breast, fresh Haas avocado, cilantro aioli', '9.25', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('e2a1ccf3-d656-4e68-8e64-81ac0d30c297', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'e7cb1522-13da-4ed4-9447-aa1b45c1d186', 'Smoked Turkey & Swiss Panini', 'Artisan sourdough with honey mustard and melted Swiss cheese', '8.50', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `products` (`id`, `business_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES ('e4874854-8fd7-4037-9650-2c5abdfa62a9', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '92842e8f-6bd0-4d04-b267-137e17cbb324', 'Butter Almond Croissant', 'Golden flaky French croissant topped with toasted almonds', '3.95', NULL, '1', '1', '0', '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `push_subscriptions`;
CREATE TABLE `push_subscriptions` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `order_id` varchar(36) NOT NULL,
  `endpoint` varchar(1000) NOT NULL,
  `p256dh_key` varchar(255) NOT NULL,
  `auth_token` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ps_order` (`order_id`),
  KEY `idx_ps_biz` (`business_id`),
  CONSTRAINT `fk_ps_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` varchar(36) NOT NULL,
  `permission_id` varchar(36) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_rp_perm` (`permission_id`),
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` varchar(36) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `description`) VALUES ('2e631065-4f52-4e67-bb62-9063ef48feab', 'super_admin', 'Platform Super Administrator with system-wide access');
INSERT INTO `roles` (`id`, `name`, `description`) VALUES ('346007aa-c82a-4d5a-b6c6-e303ff3f1f20', 'cashier', 'Front-of-house cashier for order taking & QR creation');
INSERT INTO `roles` (`id`, `name`, `description`) VALUES ('c5e0c0a2-c94f-4f7d-a0fe-10e390c69da4', 'owner', 'Tenant Business Owner with full business access');
INSERT INTO `roles` (`id`, `name`, `description`) VALUES ('ca0ae862-2599-453a-8cc0-ae6507460107', 'manager', 'Business Manager with operational management rights');
INSERT INTO `roles` (`id`, `name`, `description`) VALUES ('f738d654-65a5-4ed4-83ae-3ef13b26e768', 'kitchen', 'Kitchen/Preparation staff for order fulfillment');

DROP TABLE IF EXISTS `subscription_payments`;
CREATE TABLE `subscription_payments` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `subscription_id` varchar(36) NOT NULL,
  `plan_id` varchar(36) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `payment_method` varchar(50) NOT NULL DEFAULT 'manual_bank_transfer',
  `reference_note` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verified_by_user_id` varchar(36) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pay_biz` (`business_id`),
  KEY `idx_pay_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `subscription_plans`;
CREATE TABLE `subscription_plans` (
  `id` varchar(36) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `monthly_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `yearly_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_orders_per_month` int(11) NOT NULL DEFAULT 500,
  `max_staff_members` int(11) NOT NULL DEFAULT 5,
  `max_devices` int(11) NOT NULL DEFAULT 3,
  `allow_offline_hub` tinyint(1) NOT NULL DEFAULT 1,
  `allow_advanced_branding` tinyint(1) NOT NULL DEFAULT 0,
  `allow_exports` tinyint(1) NOT NULL DEFAULT 0,
  `data_retention_days` int(11) NOT NULL DEFAULT 90,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subscription_plans` (`id`, `code`, `name`, `monthly_price`, `yearly_price`, `max_orders_per_month`, `max_staff_members`, `max_devices`, `allow_offline_hub`, `allow_advanced_branding`, `allow_exports`, `data_retention_days`, `is_active`, `created_at`, `updated_at`) VALUES ('5d9e81ad-a49d-42b9-b869-6aa3f2b2f382', 'professional', 'Professional Plan', '49.00', '490.00', '2500', '15', '10', '1', '1', '1', '180', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `subscription_plans` (`id`, `code`, `name`, `monthly_price`, `yearly_price`, `max_orders_per_month`, `max_staff_members`, `max_devices`, `allow_offline_hub`, `allow_advanced_branding`, `allow_exports`, `data_retention_days`, `is_active`, `created_at`, `updated_at`) VALUES ('60f28040-e0ff-4299-8107-48e09a006f87', 'starter', 'Starter Plan', '19.00', '190.00', '500', '5', '3', '1', '0', '0', '60', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `subscription_plans` (`id`, `code`, `name`, `monthly_price`, `yearly_price`, `max_orders_per_month`, `max_staff_members`, `max_devices`, `allow_offline_hub`, `allow_advanced_branding`, `allow_exports`, `data_retention_days`, `is_active`, `created_at`, `updated_at`) VALUES ('97ad8b30-5b1e-4929-8684-1717448a3229', 'premium', 'Enterprise / Premium', '99.00', '990.00', '0', '50', '30', '1', '1', '1', '365', '1', '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE `subscriptions` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `plan_id` varchar(36) NOT NULL,
  `billing_cycle` enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `status` enum('trial','active','grace_period','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sub_biz` (`business_id`),
  KEY `idx_sub_status` (`status`),
  KEY `fk_sub_plan` (`plan_id`),
  CONSTRAINT `fk_sub_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subscriptions` (`id`, `business_id`, `plan_id`, `billing_cycle`, `status`, `starts_at`, `ends_at`, `trial_ends_at`, `created_at`, `updated_at`) VALUES ('44c4a170-bfeb-4968-9db0-6d15c59aa1e6', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '60f28040-e0ff-4299-8107-48e09a006f87', 'monthly', 'trial', '2026-11-04 20:59:11', '2026-11-18 18:59:11', '2026-11-18 18:59:11', '2026-11-04 20:59:11', '2026-11-04 20:59:11');

DROP TABLE IF EXISTS `sync_batches`;
CREATE TABLE `sync_batches` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `device_uuid` varchar(64) NOT NULL,
  `batch_id` varchar(64) NOT NULL,
  `orders_count` int(11) NOT NULL DEFAULT 0,
  `status_updates_count` int(11) NOT NULL DEFAULT 0,
  `conflicts_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','completed','partially_failed','failed') NOT NULL DEFAULT 'completed',
  `details_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_id` (`batch_id`),
  KEY `idx_sb_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sync_events`;
CREATE TABLE `sync_events` (
  `id` varchar(36) NOT NULL,
  `sync_batch_id` varchar(36) NOT NULL,
  `business_id` varchar(36) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(64) NOT NULL,
  `action` varchar(50) NOT NULL,
  `resolution` enum('applied','skipped_duplicate','conflict_cloud_wins','error') NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_se_batch` (`sync_batch_id`),
  KEY `idx_se_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `business_id` varchar(36) DEFAULT NULL,
  `role_id` varchar(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_usr_biz` (`business_id`),
  KEY `idx_usr_role` (`role_id`),
  CONSTRAINT `fk_usr_biz` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_usr_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `business_id`, `role_id`, `name`, `email`, `password_hash`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('057e9c7f-e903-436b-be14-61373310c93f', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', '346007aa-c82a-4d5a-b6c6-e303ff3f1f20', 'Sarah Cashier', 'cashier@sunrisecafe.com', '$2y$10$P8JHp4c8Uj3x/zqrdtne..ryy/z0VPeeFd/sscF3o6urgHPQomMQq', '1', NULL, '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `name`, `email`, `password_hash`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('7fdcc5a9-3842-4ace-b3f6-fcdeff4b635f', NULL, '2e631065-4f52-4e67-bb62-9063ef48feab', 'Platform Admin', 'admin@qrpager.io', '$2y$10$dWoEO./cMgr9Pz0VwKwXK.Rey2HhgzqAyaeD9cocuxrBMaQowaEN6', '1', NULL, '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `name`, `email`, `password_hash`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('86e1889c-2e06-49a5-887c-8ffa960dc7e7', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'c5e0c0a2-c94f-4f7d-a0fe-10e390c69da4', 'John Sunrise (Owner)', 'owner@sunrisecafe.com', '$2y$10$v.7aqjrVp03obthsQF1T8.Ul51dN.XGyYt7qx7pdcwQFVGhDElWf.', '1', NULL, '2026-11-04 20:59:11', '2026-11-04 20:59:11');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `name`, `email`, `password_hash`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('ea11f0e8-8b0c-4eb8-9e10-7427f03076ee', 'c05552d1-6c8b-4abb-9afd-d60b16ea63e1', 'f738d654-65a5-4ed4-83ae-3ef13b26e768', 'Chef Marco', 'kitchen@sunrisecafe.com', '$2y$10$Oe7NAt/QVctjT4mH4UGt2OVqhGruJUftncArjVuwWBPKzZjBOdMYO', '1', NULL, '2026-11-04 20:59:11', '2026-11-04 20:59:11');

SET FOREIGN_KEY_CHECKS = 1;
