<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Translation;
use App\Core\Session;
use App\Core\Database;

class TenantMiddleware
{
    private static ?string $currentTenantId = null;
    private static ?array $currentTenant = null;

    public function handle(Request $request, Response $response): bool
    {
        // Must be authenticated first
        if (!Auth::check()) {
            $response->redirect('/login')->send();
            return false;
        }

        // If Super Admin, they have platform-level access
        if (Auth::isSuperAdmin()) {
            return true;
        }

        $businessId = Auth::businessId();
        if (!$businessId) {
            $response->json(['error' => 'No associated business found for this account.'], 403)->send();
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT b.*, bs.currency_symbol, bs.order_number_prefix, bs.qr_expiration_grace_minutes, 
                   bs.estimated_prep_time_minutes, bs.enable_offline_hub,
                   bb.primary_color, bb.accent_color, bb.logo_url
            FROM businesses b
            LEFT JOIN business_settings bs ON b.id = bs.business_id
            LEFT JOIN business_branding bb ON b.id = bb.business_id
            WHERE b.id = ?
            LIMIT 1
        ");
        $stmt->execute([$businessId]);
        $business = $stmt->fetch();

        if (!$business) {
            $response->json(['error' => 'Business workspace does not exist.'], 404)->send();
            return false;
        }

        self::$currentTenantId = $businessId;
        self::$currentTenant = $business;

        // Apply tenant default language if session doesn't override
        if (!Session::has('app_locale') && !empty($business['default_language'])) {
            Translation::setLocale($business['default_language']);
        }

        return true;
    }

    public static function getTenantId(): ?string
    {
        return self::$currentTenantId ?? Auth::businessId();
    }

    public static function getTenant(): ?array
    {
        return self::$currentTenant;
    }
}
