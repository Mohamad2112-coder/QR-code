<?php

declare(strict_types=1);

namespace LocalHub;

use PDO;

class HubDatabase
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $storageDir = __DIR__ . '/storage';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            $dbPath = $storageDir . '/hub_local.sqlite';
            self::$pdo = new PDO("sqlite:{$dbPath}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            self::initSchema(self::$pdo);
        }

        return self::$pdo;
    }

    private static function initSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS local_catalog (
                id TEXT PRIMARY KEY,
                category_name TEXT,
                name TEXT NOT NULL,
                price REAL NOT NULL,
                is_available INTEGER DEFAULT 1,
                updated_at TEXT
            );

            CREATE TABLE IF NOT EXISTS local_orders (
                id TEXT PRIMARY KEY,
                order_number TEXT NOT NULL,
                tracking_token TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT 'pending',
                total_amount REAL NOT NULL,
                customer_notes TEXT,
                sync_state TEXT NOT NULL DEFAULT 'pending_sync', -- pending_sync, synced
                created_at TEXT NOT NULL,
                preparing_at TEXT,
                ready_at TEXT,
                collected_at TEXT
            );

            CREATE TABLE IF NOT EXISTS local_order_items (
                id TEXT PRIMARY KEY,
                order_id TEXT NOT NULL,
                product_id TEXT,
                name TEXT NOT NULL,
                price REAL NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                notes TEXT,
                subtotal REAL NOT NULL,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS local_status_updates (
                id TEXT PRIMARY KEY,
                order_id TEXT NOT NULL,
                new_status TEXT NOT NULL,
                transition_time TEXT NOT NULL,
                sync_state TEXT NOT NULL DEFAULT 'pending_sync'
            );

            CREATE TABLE IF NOT EXISTS local_audit_log (
                id TEXT PRIMARY KEY,
                event_type TEXT NOT NULL,
                description TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
        ");
    }
}
