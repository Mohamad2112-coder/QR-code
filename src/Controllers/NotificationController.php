<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Database;
use App\Services\NotificationService;
use Exception;

class NotificationController
{
    /**
     * Return public VAPID key for browser push subscription initialization.
     */
    public function getVapidKey(Request $request, Response $response): void
    {
        $vapidConfig = Config::load('vapid');
        $response->json([
            'public_key' => $vapidConfig['public_key']
        ])->send();
    }

    /**
     * Customer Push Notification Registration Endpoint
     */
    public function subscribe(Request $request, Response $response): void
    {
        $token = trim((string)$request->input('token'));
        $endpoint = trim((string)$request->input('endpoint'));
        $p256dh = trim((string)$request->input('p256dh'));
        $auth = trim((string)$request->input('auth'));

        if (empty($token) || empty($endpoint) || empty($p256dh) || empty($auth)) {
            $response->json(['error' => 'Invalid push subscription parameters.'], 422)->send();
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, business_id FROM orders WHERE tracking_token = ?");
        $stmt->execute([$token]);
        $order = $stmt->fetch();

        if (!$order) {
            $response->json(['error' => 'Order not found.'], 404)->send();
            return;
        }

        $res = NotificationService::subscribe(
            $order['business_id'],
            $order['id'],
            $endpoint,
            $p256dh,
            $auth,
            $request->userAgent()
        );

        $response->json([
            'status' => 'success',
            'result' => $res
        ])->send();
    }

    /**
     * Process pending notification jobs (CLI or background trigger)
     */
    public function runQueue(Request $request, Response $response): void
    {
        $limit = (int)($request->query('limit', 20));
        $res = NotificationService::processPendingJobs($limit);
        $response->json(['status' => 'success', 'details' => $res])->send();
    }
}
