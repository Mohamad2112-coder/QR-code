<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/hub_database.php';

use LocalHub\HubDatabase;
use App\Core\UUID;
use App\Services\QRCodeService;

$configFile = __DIR__ . '/config.json';
$config = json_decode((string)file_get_contents($configFile), true);
$db = HubDatabase::getConnection();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Route: API Create Order on LAN
if ($uri === '/api/pos/order' && $method === 'POST') {
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];

    $items = $data['items'] ?? [];
    if (empty($items)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cart is empty']);
        exit;
    }

    $orderId = UUID::v4();
    $token = 'tk_off_' . bin2hex(random_bytes(24));
    
    // Order numbering
    $stmtCount = $db->query("SELECT COUNT(*) FROM local_orders");
    $num = ((int)$stmtCount->fetchColumn()) + 1;
    $orderNumber = 'LAN-' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);

    $total = 0;
    foreach ($items as $it) {
        $total += (float)$it['price'] * max(1, (int)$it['quantity']);
    }

    $now = date('Y-m-d H:i:s');
    $db->prepare("
        INSERT INTO local_orders (id, order_number, tracking_token, status, total_amount, customer_notes, sync_state, created_at)
        VALUES (?, ?, ?, 'pending', ?, ?, 'pending_sync', ?)
    ")->execute([$orderId, $orderNumber, $token, $total, $data['notes'] ?? '', $now]);

    $stmtItem = $db->prepare("
        INSERT INTO local_order_items (id, order_id, product_id, name, price, quantity, notes, subtotal, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $it) {
        $sub = (float)$it['price'] * max(1, (int)$it['quantity']);
        $stmtItem->execute([
            UUID::v4(), $orderId, $it['product_id'] ?? null, $it['name'],
            (float)$it['price'], (int)$it['quantity'], $it['notes'] ?? '', $sub, $now
        ]);
    }

    $cloudTrackingUrl = rtrim($config['cloud_url'], '/') . "/track/{$token}";
    $qrSvg = QRCodeService::generateSvgDataUri($cloudTrackingUrl);

    echo json_encode([
        'status' => 'success',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'tracking_url' => $cloudTrackingUrl,
        'qr_svg' => $qrSvg,
        'total' => $total,
        'mode' => 'offline_local_hub'
    ]);
    exit;
}

// Route: API KDS Orders on LAN
if ($uri === '/api/kitchen/orders' && $method === 'GET') {
    header('Content-Type: application/json');
    $stmt = $db->query("SELECT * FROM local_orders WHERE status IN ('pending', 'preparing', 'ready') ORDER BY created_at ASC");
    $orders = $stmt->fetchAll();

    foreach ($orders as &$ord) {
        $stmtItems = $db->prepare("SELECT name as product_name_snapshot, quantity, notes as item_notes FROM local_order_items WHERE order_id = ?");
        $stmtItems->execute([$ord['id']]);
        $ord['items'] = $stmtItems->fetchAll();
        $ord['elapsed_seconds'] = time() - strtotime($ord['created_at']);
    }

    echo json_encode(['status' => 'success', 'orders' => $orders]);
    exit;
}

// Route: API KDS Status Update on LAN
if (preg_match('#^/api/kitchen/orders/([^/]+)/status$#', $uri, $matches) && $method === 'POST') {
    header('Content-Type: application/json');
    $orderId = $matches[1];
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    $newStatus = $data['status'] ?? 'preparing';

    $db->prepare("UPDATE local_orders SET status = ? WHERE id = ?")->execute([$newStatus, $orderId]);
    
    // Queue status update for cloud sync
    $db->prepare("INSERT INTO local_status_updates (id, order_id, new_status, transition_time, sync_state) VALUES (?, ?, ?, ?, 'pending_sync')")
        ->execute([UUID::v4(), $orderId, $newStatus, date('Y-m-d H:i:s')]);

    echo json_encode(['status' => 'success']);
    exit;
}

// Route: HTML POS / KDS Screen on LAN
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Local Business Hub - <?= htmlspecialchars($config['business_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body style="background: #f8fafc; padding: 1.5rem;">
  <div style="max-width: 900px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
      <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #0284c7;">🖥️ Local Business Hub</h1>
        <p style="color: #64748b;"><?= htmlspecialchars($config['business_name']) ?> • LAN Edge Server Active</p>
      </div>
      <span class="badge badge-offline">Offline Edge Hub (LAN)</span>
    </div>

    <div class="grid grid-2">
      <div class="card" style="text-align: center; padding: 2rem;">
        <div style="font-size: 3rem; margin-bottom: 0.5rem;">🛒</div>
        <h2 style="font-size: 1.3rem; font-weight: 700; margin-bottom: 0.5rem;">Cashier POS Terminal</h2>
        <p style="color: #64748b; margin-bottom: 1.5rem;">Create orders and generate QR digital pagers completely offline.</p>
        <a href="/orders/create" class="btn btn-primary" style="width: 100%;">Open Cashier POS</a>
      </div>

      <div class="card" style="text-align: center; padding: 2rem;">
        <div style="font-size: 3rem; margin-bottom: 0.5rem;">🍳</div>
        <h2 style="font-size: 1.3rem; font-weight: 700; margin-bottom: 0.5rem;">Kitchen KDS Display</h2>
        <p style="color: #64748b; margin-bottom: 1.5rem;">View active kitchen orders and update prep states on the local LAN.</p>
        <a href="/kitchen" class="btn btn-accent" style="width: 100%;">Open Kitchen Display</a>
      </div>
    </div>
  </div>
</body>
</html>
