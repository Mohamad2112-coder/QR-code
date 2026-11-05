<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

class RoleMiddleware
{
    private array $allowedRoles;

    public function __construct(array $allowedRoles = ['owner', 'manager', 'cashier', 'kitchen', 'super_admin'])
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle(Request $request, Response $response): bool
    {
        if (!Auth::check()) {
            $response->redirect('/login')->send();
            return false;
        }

        $userRole = Auth::role();
        if ($userRole === 'super_admin') {
            return true; // Super Admin has platform-wide authority
        }

        if (!in_array($userRole, $this->allowedRoles, true)) {
            if ($request->isAjaxOrJson()) {
                $response->json(['error' => 'Forbidden. Insufficient role permissions.'], 403)->send();
            } else {
                $response->html('<h1>403 Forbidden</h1><p>You do not have permission to access this area.</p>', 403)->send();
            }
            return false;
        }

        return true;
    }
}
