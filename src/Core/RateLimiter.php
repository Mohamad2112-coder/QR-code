<?php

declare(strict_types=1);

namespace App\Core;

class RateLimiter
{
    private static string $storageDir = '';

    private static function initStorage(): void
    {
        if (empty(self::$storageDir)) {
            self::$storageDir = sys_get_temp_dir() . '/qrpager_ratelimits';
            if (!is_dir(self::$storageDir)) {
                @mkdir(self::$storageDir, 0755, true);
            }
        }
    }

    /**
     * Check if a key has exceeded max attempts in the given decay seconds.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        self::initStorage();
        $file = self::$storageDir . '/' . md5($key) . '.json';
        $now = time();

        if (file_exists($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if ($data && isset($data['reset_at']) && $data['reset_at'] > $now) {
                return $data['attempts'] >= $maxAttempts;
            }
        }
        return false;
    }

    /**
     * Increment attempts count for key.
     */
    public static function hit(string $key, int $decaySeconds = 60): int
    {
        self::initStorage();
        $file = self::$storageDir . '/' . md5($key) . '.json';
        $now = time();

        $attempts = 1;
        $resetAt = $now + $decaySeconds;

        if (file_exists($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if ($data && isset($data['reset_at']) && $data['reset_at'] > $now) {
                $attempts = ($data['attempts'] ?? 0) + 1;
                $resetAt = $data['reset_at'];
            }
        }

        file_put_contents($file, json_encode([
            'attempts' => $attempts,
            'reset_at' => $resetAt
        ]));

        return $attempts;
    }

    /**
     * Reset rate limit attempts for key.
     */
    public static function clear(string $key): void
    {
        self::initStorage();
        $file = self::$storageDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
