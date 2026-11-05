<?php

declare(strict_types=1);

namespace App\Core;

class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'main'): string
    {
        // Extract parameters into local scope
        extract($data, EXTR_SKIP);

        $viewFile = dirname(__DIR__, 2) . "/views/{$view}.php";
        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View file not found: {$view}");
        }

        // Global view helpers
        $e = fn(?string $string): string => htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
        $__ = fn(string $key, array $replace = []): string => Translation::trans($key, $replace);
        $csrfField = fn(): string => CSRF::field();
        $csrfToken = fn(): string => CSRF::token();
        $flash = fn(string $key): ?string => Session::flash($key);
        $auth = Auth::user();
        $direction = Translation::getDirection();
        $currentLocale = Translation::getLocale();

        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        $layoutFile = dirname(__DIR__, 2) . "/views/layouts/{$layout}.php";
        if (!file_exists($layoutFile)) {
            return $content;
        }

        ob_start();
        include $layoutFile;
        return ob_get_clean();
    }
}
