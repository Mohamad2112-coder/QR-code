<?php

declare(strict_types=1);

namespace App\Core;

class Translation
{
    private static string $locale = 'en';
    private static array $translations = [];

    public static function setLocale(string $locale): void
    {
        $supported = Config::get('app.supported_locales', ['en', 'ar', 'ku']);
        if (in_array($locale, $supported, true)) {
            self::$locale = $locale;
        } else {
            self::$locale = 'en';
        }
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function isRtl(?string $locale = null): bool
    {
        $loc = $locale ?? self::$locale;
        return in_array($loc, ['ar', 'ku'], true);
    }

    public static function getDirection(?string $locale = null): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }

    public static function loadLocale(string $locale): array
    {
        if (!isset(self::$translations[$locale])) {
            $path = dirname(__DIR__, 2) . "/locales/{$locale}.json";
            if (file_exists($path)) {
                $content = file_get_contents($path);
                self::$translations[$locale] = json_decode($content, true) ?? [];
            } else {
                self::$translations[$locale] = [];
            }
        }
        return self::$translations[$locale];
    }

    public static function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        $loc = $locale ?? self::$locale;
        $translations = self::loadLocale($loc);

        // Fallback to English if translation is missing
        if (!isset($translations[$key]) && $loc !== 'en') {
            $fallback = self::loadLocale('en');
            $text = $fallback[$key] ?? $key;
        } else {
            $text = $translations[$key] ?? $key;
        }

        foreach ($replace as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, (string)$value, $text);
            $text = str_replace('{' . $placeholder . '}', (string)$value, $text);
        }

        return $text;
    }
}
