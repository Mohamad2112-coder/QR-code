<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

class SubscriptionMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }

        $businessId = Auth::businessId();
        if (!$businessId) {
            return true;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT status, trial_ends_at FROM businesses WHERE id = ?");
        $stmt->execute([$businessId]);
        $biz = $stmt->fetch();

        if ($biz) {
            if ($biz['status'] === 'suspended') {
                $response->html('<h1>Account Suspended</h1><p>Your subscription is suspended. Please contact platform administration to renew.</p>', 403)->send();
                return false;
            }

            if ($biz['status'] === 'trial' && strtotime($biz['trial_ends_at']) < time()) {
                // Trial expired
                $path = $request->getUri();
                if (!str_starts_with($path, '/subscription') && !str_starts_with($path, '/logout')) {
                    $response->redirect('/subscription/expired')->send();
                    return false;
                }
            }
        }

        return true;
    }
}
