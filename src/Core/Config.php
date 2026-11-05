<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private static array $configs = [];

    public static function load(string $name): array
    {
        if (!isset(self::$configs[$name])) {
            $path = dirname(__DIR__, 2) . "/config/{$name}.php";
            if (file_exists($path)) {
                self::$configs[$name] = require $path;
            } else {
                self::$configs[$name] = [];
            }
        }
        return self::$configs[$name];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        $config = self::load($file);

        foreach ($parts as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return $default;
            }
            $config = $config[$part];
        }

        return $config;
    }
}
