<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\RateLimiter;
use App\Core\Translation;
use App\Core\Session;
use App\Services\OrderService;

class CustomerTrackingController
{
    /**
     * Public Customer Tracking Page
     */
    public function track(Request $request, Response $response, array $params): void
    {
        $token = $params['token'] ?? '';
        $ip = $request->ip();

        // Rate limit tracking requests: Max 100 requests per minute per IP
        if (RateLimiter::tooManyAttempts("track_ip_{$ip}", 100, 60)) {
            $response->html('<h1>429 Too Many Requests</h1><p>Please wait a moment before refreshing.</p>', 429)->send();
            return;
        }
        RateLimiter::hit("track_ip_{$ip}", 60);

        // Language selection by query param or session
        $requestedLocale = $request->query('lang');
        if ($requestedLocale && in_array($requestedLocale, ['en', 'ar', 'ku'], true)) {
            Translation::setLocale($requestedLocale);
            Session::set('customer_locale', $requestedLocale);
        } elseif (Session::has('customer_locale')) {
            Translation::setLocale(Session::get('customer_locale'));
        }

        $order = OrderService::getCustomerOrder($token);

        if (!$order) {
            $html = View::render('customer/not_found', [
                'title' => Translation::trans('order_not_found'),
                'token' => $token
            ], 'customer');
            $response->html($html, 404)->send();
            return;
        }

        if (isset($order['expired']) && $order['expired'] === true) {
            $html = View::render('customer/expired', [
                'title' => Translation::trans('customer_track_title'),
                'order' => $order
            ], 'customer');
            $response->html($html)->send();
            return;
        }

        // If customer hasn't manually selected language, use business default
        if (!Session::has('customer_locale') && !empty($order['default_language'])) {
            Translation::setLocale($order['default_language']);
        }

        $html = View::render('customer/track', [
            'title' => "{$order['business_name']} - " . Translation::trans('customer_order_number', ['order_number' => $order['order_number']]),
            'order' => $order,
            'direction' => Translation::getDirection(),
            'currentLocale' => Translation::getLocale()
        ], 'customer');

        $response->html($html)->send();
    }

    /**
     * Real-time Order Status Poll API for Customer Mobile Tracking Screen
     */
    public function statusApi(Request $request, Response $response, array $params): void
    {
        $token = $params['token'] ?? '';
        $order = OrderService::getCustomerOrder($token);

        if (!$order) {
            $response->json(['error' => 'Order not found'], 404)->send();
            return;
        }

        if (isset($order['expired']) && $order['expired'] === true) {
            $response->json(['status' => 'expired', 'order_number' => $order['order_number']])->send();
            return;
        }

        $response->json([
            'status' => $order['status'],
            'order_number' => $order['order_number'],
            'preparing_at' => $order['preparing_at'],
            'ready_at' => $order['ready_at'],
            'collected_at' => $order['collected_at'],
            'estimated_minutes' => $order['estimated_prep_time_minutes'],
            'updated_timestamp' => time()
        ])->send();
    }
}
