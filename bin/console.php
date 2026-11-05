<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\BackupService;
use App\Services\NotificationService;
use Minishlink\WebPush\VAPID;

$command = $argv[1] ?? 'help';

echo "=========================================================\n";
echo "   QR Order Pager SaaS Platform CLI Console              \n";
echo "=========================================================\n";

switch ($command) {
    case 'migrate':
        echo "Executing database migrations...\n";
        $dbConfig = Config::load('database');
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $dbConfig['options']);
        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        $pdo->exec($sql);
        echo "Database schema migrated successfully!\n";
        break;

    case 'seed':
        echo "Seeding database with initial platform & tenant data...\n";
        require_once __DIR__ . '/../database/seed.php';
        $dbConfig = Config::load('database');
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $dbConfig['options']);
        seedDatabase($pdo);
        break;

    case 'generate-vapid':
        echo "Generating new VAPID cryptographic keypair for Web Push...\n";
        try {
            $keys = VAPID::createVapidKeys();
            echo "VAPID Public Key:\n" . $keys['publicKey'] . "\n\n";
            echo "VAPID Private Key:\n" . $keys['privateKey'] . "\n\n";
            echo "Add these to your .env or config/vapid.php\n";
        } catch (\Throwable $e) {
            echo "Error generating VAPID keys: " . $e->getMessage() . "\n";
        }
        break;

    case 'run-notifications':
        echo "Dispatching pending push notification jobs...\n";
        $result = NotificationService::processPendingJobs(50);
        echo "Jobs Processed: {$result['processed']} | Successful: {$result['successful']} | Failed: {$result['failed']}\n";
        break;

    case 'backup':
        echo "Creating automated database backup...\n";
        $res = BackupService::createBackup();
        echo "Backup created successfully: {$res['filename']} ({$res['size_bytes']} bytes)\n";
        break;

    case 'restore':
        $filepath = $argv[2] ?? null;
        if (!$filepath) {
            echo "Error: Please specify the backup SQL file to restore.\n";
            echo "Usage: php bin/console.php restore storage/backups/backup_xxxx.sql\n";
            exit(1);
        }
        echo "Restoring database from: {$filepath}...\n";
        BackupService::restoreBackup($filepath);
        echo "Database restored successfully!\n";
        break;

    case 'list-backups':
        echo "Listing available database backups:\n";
        $list = BackupService::listBackups();
        if (empty($list)) {
            echo "No backups found.\n";
        } else {
            foreach ($list as $b) {
                echo "• {$b['filename']} (" . round($b['size_bytes'] / 1024, 1) . " KB) - {$b['modified_at']}\n";
            }
        }
        break;

    default:
        echo "Available Commands:\n";
        echo "  php bin/console.php migrate             Run schema migrations\n";
        echo "  php bin/console.php seed                Seed roles, plans, demo tenant & orders\n";
        echo "  php bin/console.php generate-vapid      Generate Web Push VAPID keypair\n";
        echo "  php bin/console.php run-notifications   Dispatch queued Ready notifications\n";
        echo "  php bin/console.php backup              Create a full database backup\n";
        echo "  php bin/console.php restore <file>      Restore database from SQL dump\n";
        echo "  php bin/console.php list-backups        List all existing backup files\n";
        break;
}
