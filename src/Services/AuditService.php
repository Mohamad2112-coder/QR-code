<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\UUID;
use App\Core\Auth;

class AuditService
{
    public static function log(
        string $eventType,
        string $description,
        ?string $businessId = null,
        ?string $userId = null,
        array $context = []
    ): void {
        try {
            $db = Database::getConnection();
            $bizId = $businessId ?? Auth::businessId();
            $uId = $userId ?? Auth::id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System';

            $stmt = $db->prepare("
                INSERT INTO audit_logs (id, business_id, user_id, event_type, description, ip_address, user_agent, context_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                UUID::v4(),
                $bizId,
                $uId,
                $eventType,
                $description,
                $ip,
                substr($ua, 0, 255),
                empty($context) ? null : json_encode($context, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (\Throwable $e) {
            // Silently prevent audit logging failures from interrupting primary transactions
            error_log("Audit log failure: " . $e->getMessage());
        }
    }
}
