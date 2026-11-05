<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

class Auth
{
    private const SESSION_USER_ID = '_auth_user_id';
    private static ?array $cachedUser = null;

    public static function attempt(string $email, string $password): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, b.status as business_status, b.name as business_name, b.trial_ends_at
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN businesses b ON u.business_id = b.id
            WHERE u.email = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            self::login($user);
            
            // Record last login
            $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
            return true;
        }

        return false;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set(self::SESSION_USER_ID, $user['id']);
        self::$cachedUser = $user;
    }

    public static function logout(): void
    {
        Session::remove(self::SESSION_USER_ID);
        self::$cachedUser = null;
        Session::destroy();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $userId = Session::get(self::SESSION_USER_ID);
        if (!$userId) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, b.status as business_status, b.name as business_name, b.trial_ends_at
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN businesses b ON u.business_id = b.id
            WHERE u.id = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            self::logout();
            return null;
        }

        self::$cachedUser = $user;
        return self::$cachedUser;
    }

    public static function id(): ?string
    {
        $user = self::user();
        return $user['id'] ?? null;
    }

    public static function businessId(): ?string
    {
        $user = self::user();
        return $user['business_id'] ?? null;
    }

    public static function role(): ?string
    {
        $user = self::user();
        return $user['role_name'] ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === 'super_admin';
    }

    public static function hasRole(string|array $roles): bool
    {
        $currentRole = self::role();
        if (!$currentRole) {
            return false;
        }
        if (is_array($roles)) {
            return in_array($currentRole, $roles, true);
        }
        return $currentRole === $roles;
    }
}
