# Local Business Hub Installation & Operation Guide

## 1. What is the Local Business Hub?
The **Local Business Hub** is an edge appliance software that runs inside a business's local network (LAN) on a designated POS computer, mini-PC (e.g. Intel NUC), or Raspberry Pi.

It allows Cashiers and Kitchen staff to continue creating orders, printing customer QR slips, and updating cooking states even when the external internet connection fails.

---

## 2. Prerequisites
1. Any computer on the business LAN (Windows 10/11, macOS, or Linux).
2. PHP 8.2+ installed with `pdo_sqlite` and `curl`.
3. A static or reserved local IP address on the router (e.g., `192.168.1.100`).

---

## 3. Configuration

In `local_hub/config.json`:
```json
{
  "cloud_url": "https://app.yourdomain.com",
  "business_id": "YOUR_BUSINESS_UUID",
  "device_uuid": "dev_hub_sunrise_edge_01",
  "device_secret": "YOUR_DEVICE_SECRET_TOKEN",
  "lan_port": 8080,
  "sync_interval_seconds": 10,
  "business_name": "Sunrise Café & Bistro"
}
```
> Obtain your `business_id` and register a device to generate `device_uuid` and `device_secret` in the Business Dashboard under **Settings > Local Business Hub**.

---

## 4. Starting the Local Hub

### On Windows:
Double-click `local_hub/run_hub.bat` or run in terminal:
```cmd
cd local_hub
run_hub.bat
```

### On Linux / Raspberry Pi:
Run as a background systemd service or via CLI:
```bash
# Terminal 1: Edge Web Server
php -S 0.0.0.0:8080 hub_server.php

# Terminal 2: Background Cloud Sync Worker
php hub_sync_worker.php
```

---

## 5. How Synchronization Works

1. **Continuous Heartbeat**: `hub_sync_worker.php` attempts to connect to `GET /api/hub/catalog` on the cloud every 10 seconds.
2. **Offline Detection**: If the connection times out, the Hub logs:
   ```
   [14:02:10] Cloud offline / unreachable. Local Hub operating autonomously.
   ```
3. **Queueing**: Any orders created on the LAN are stored with `sync_state = 'pending_sync'` in `local_hub/storage/hub_local.sqlite`.
4. **Automatic Reconnection & Batch Upload**: When internet connectivity returns, the sync worker bundles all pending orders and status updates into an idempotent batch and POSTs to `/api/hub/sync`.
5. **Conflict Resolution**: Stale offline status transitions are automatically compared against cloud status rank. The cloud state is preserved if it has already progressed further.
