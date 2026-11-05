<?php

declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'QR Order Pager SaaS',
    'env'  => getenv('APP_ENV') ?: 'production',
    'debug' => (bool)(getenv('APP_DEBUG') ?: false),
    'url'  => getenv('APP_URL') ?: 'http://localhost:8000',
    'timezone' => 'UTC',
    'default_locale' => 'en',
    'supported_locales' => ['en', 'ar', 'ku'],
    'session_lifetime' => (int)(getenv('SESSION_LIFETIME') ?: 86400),
    'rate_limit_per_minute' => (int)(getenv('RATE_LIMIT_PER_MINUTE') ?: 120),
];
