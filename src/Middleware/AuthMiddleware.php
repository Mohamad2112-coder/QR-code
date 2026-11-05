<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

class AuthMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        if (!Auth::check()) {
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => 'Unauthenticated'], 401)->send();
            } else {
                $response->redirect('/login')->send();
            }
            return false;
        }

        return true;
    }
}
