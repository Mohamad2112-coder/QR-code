# System Architecture & Technical Specifications

## 1. Overview & Vision
The **Multi-Tenant QR Order Pager SaaS Platform** is a commercial solution enabling hospitality and retail businesses (restaurants, cafés, fast food, bakeries, juice shops) to transform customers' smartphones into digital order pagers without requiring app installation, phone number collection, or customer account creation.

The platform employs a hybrid architecture:
1. **Central Cloud Platform**: Authoritative multi-tenant cloud SaaS for registration, tenant workspaces, products, staff, reporting, subscriptions, public HTTPS QR tracking, and Web Push notifications.
2. **Local Business Hub (LAN Edge Server)**: Lightweight local edge server enabling front-of-house Cashier POS and Kitchen KDS to operate autonomously during internet outages, queueing offline orders and state transitions in local SQLite durable storage and synchronizing seamlessly with the cloud when connectivity returns.

---

## 2. Component Architecture

```
+---------------------------------------------------------------------------------+
|                              CENTRAL CLOUD SAAS                                 |
|  - Multi-Tenant Engine                                                          |
|  - Cloud MySQL 8 Database                                                       |
|  - Authentication & RBAC (Super Admin, Owner, Manager, Cashier, Kitchen)        |
|  - Public HTTPS Customer Tracking (/track/{token})                              |
|  - Web Push Notification Dispatcher (VAPID)                                     |
|  - Synchronization Ingestion Endpoint (/api/hub/sync)                           |
+---------------------------------------------------------------------------------+
                                       ▲
                                       │ HTTPS / REST (Idempotent Sync Batches)
                                       ▼
+---------------------------------------------------------------------------------+
|                        LOCAL BUSINESS HUB (EDGE SERVER)                         |
|  - Local Durable SQLite Database (hub_local.sqlite)                             |
|  - Local Web Server (Cashier POS & Kitchen KDS endpoints)                       |
|  - Offline Order Generator & Idempotent UUID Token Assignor                     |
|  - Background Sync Daemon (hub_sync_worker.php)                                 |
+---------------------------------------------------------------------------------+
       ▲                                                           ▲
       │ Local LAN (WiFi / Ethernet)                               │ Local LAN (WiFi)
       ▼                                                           ▼
[ Cashier POS Tablet / PC ]                              [ Kitchen KDS Display ]
```

---

## 3. Order Status State Machine & Workflow

```
   [ PENDING ] ───────────► [ PREPARING ] ───────────► [ READY ] ───────────► [ COLLECTED ]
        │                         │                        │
        │                         │                        └─► (Enqueues 1 Web Push Notification)
        ▼                         ▼
   [ CANCELLED ] ◄────────────────┘ (Terminal outcome with audit log)
```

### State Transition Validation Rules:
1. Valid Forward Progression:
   - `pending` -> `preparing`
   - `preparing` -> `ready`
   - `ready` -> `collected`
2. Cancellation:
   - Allowed only from `pending` or `preparing`.
   - Cancellation is a terminal outcome and cannot be reopened.
3. Anti-Rollback Integrity:
   - An order that has reached `collected` can never transition backwards.
   - Stale offline events (e.g. an edge device sending `preparing` for an order already marked `collected` in the cloud) are resolved in favor of the cloud (`conflict_cloud_wins`).

---

## 4. Multi-Tenant Isolation & Security
- **Tenant Key Scope**: Every tenant-owned record in MySQL (`orders`, `order_items`, `products`, `product_categories`, `push_subscriptions`, `local_devices`, `sync_batches`, `audit_logs`) carries a mandatory `business_id` foreign key.
- **Tenant Authorization Middleware**: `TenantMiddleware` extracts the authenticated user's workspace context and binds all queries to that `business_id`.
- **Anti-IDOR Enforcement**: Cross-tenant data retrieval is forbidden. Queries attempting to query an order or product outside the user's `business_id` return empty or 403 Forbidden.
- **Public Tracking Security**: The customer tracking endpoint `/track/{token}` accepts only a 48-character unguessable cryptographic token (`tk_...`). Sequential auto-increment database IDs are never exposed in public tracking links or responses. Internal staff notes and employee details are masked from public customer responses.

---

## 5. Offline Operation & Automatic Synchronization
1. **Durable Local Storage**: The Local Business Hub uses SQLite (`local_hub/storage/hub_local.sqlite`).
2. **Unified Tokens**: Orders created offline are immediately given the exact same public HTTPS URL (`https://app.qrpager.io/track/{token}`) and printed on customer slips.
3. **Graceful Customer Experience**: If a customer scans the QR code before the Local Hub has synced with the cloud, the customer tracking screen displays a friendly message: *"Order Being Registered — placed at the counter and synchronizing"*, and auto-polls every 5 seconds until cloud sync completes.
4. **Idempotency Keys**: Offline orders are assigned unique idempotency keys (`idempotency_key = "hub_ord_" + UUID`). If a network timeout occurs and a sync batch is resent, the cloud engine detects duplicate keys and safely skips duplicate insertions.
5. **Auditable Sync Log**: Every synchronization batch is recorded in `sync_batches` and itemized in `sync_events` with resolution tags: `applied`, `skipped_duplicate`, or `conflict_cloud_wins`.
