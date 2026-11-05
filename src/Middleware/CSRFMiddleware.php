<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\CSRF;
use App\Core\Request;
use App\Core\Response;

class CSRFMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        $method = $request->getMethod();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        // Exclude local hub sync and public customer push registration endpoints which use token auth
        $uri = $request->getUri();
        if (str_starts_with($uri, '/api/hub/') || str_starts_with($uri, '/api/customer/push-subscribe')) {
            return true;
        }

        $token = $request->input('_token') ?? $request->getHeader('X-CSRF-TOKEN');

        if (!CSRF::validate($token)) {
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => 'CSRF token mismatch or expired. Please refresh the page.'], 419)->send();
            } else {
                $response->html('<h1>419 Page Expired</h1><p>CSRF token mismatch. Please go back, refresh the page, and try again.</p>', 419)->send();
            }
            return false;
        }

        return true;
    }
}
