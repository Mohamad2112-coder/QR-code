<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use Exception;

class BackupService
{
    private static function getBackupDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Create a full SQL dump backup of the database.
     */
    public static function createBackup(): array
    {
        $backupDir = self::getBackupDir();
        $filename = 'backup_' . date('Y_m_d_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $filepath = $backupDir . '/' . $filename;

        $db = Database::getConnection();
        $tables = [];
        $stmt = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $out = "-- QR Order Pager SaaS Platform Automated Database Backup\n";
        $out .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            // Table structure
            $stmtCreate = $db->query("SHOW CREATE TABLE `{$table}`");
            $rowCreate = $stmtCreate->fetch(PDO::FETCH_NUM);
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $out .= $rowCreate[1] . ";\n\n";

            // Table data
            $stmtData = $db->query("SELECT * FROM `{$table}`");
            $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $colNames = implode('`, `', $columns);

                foreach ($rows as $row) {
                    $values = array_map(function ($val) use ($db) {
                        if ($val === null) return 'NULL';
                        return $db->quote((string)$val);
                    }, array_values($row));
                    $valList = implode(', ', $values);
                    $out .= "INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$valList});\n";
                }
                $out .= "\n";
            }
        }

        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        file_put_contents($filepath, $out);
        chmod($filepath, 0640);

        // Retention policy: Keep the last 15 backups, prune older ones
        self::pruneOldBackups(15);

        AuditService::log('database_backup_created', "Database backup created: {$filename}");

        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'size_bytes' => filesize($filepath),
            'tables_count' => count($tables),
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Restore database from a specific SQL backup file.
     */
    public static function restoreBackup(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            throw new Exception("Backup file not found: {$filepath}");
        }

        $sql = file_get_contents($filepath);
        if (!$sql) {
            throw new Exception("Backup file is empty or unreadable.");
        }

        $db = Database::getConnection();
        $db->exec($sql);

        AuditService::log('database_backup_restored', "Database restored from: " . basename($filepath));
        return true;
    }

    /**
     * Prune backups older than retention count.
     */
    public static function pruneOldBackups(int $keepCount = 15): int
    {
        $backupDir = self::getBackupDir();
        $files = glob($backupDir . '/backup_*.sql');
        if (count($files) <= $keepCount) {
            return 0;
        }

        // Sort oldest first
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));

        $deleted = 0;
        $toDelete = count($files) - $keepCount;
        for ($i = 0; $i < $toDelete; $i++) {
            if (@unlink($files[$i])) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * List all available backups.
     */
    public static function listBackups(): array
    {
        $backupDir = self::getBackupDir();
        $files = glob($backupDir . '/backup_*.sql');
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $list = [];
        foreach ($files as $f) {
            $list[] = [
                'filename' => basename($f),
                'size_bytes' => filesize($f),
                'modified_at' => date('Y-m-d H:i:s', filemtime($f))
            ];
        }
        return $list;
    }
}
