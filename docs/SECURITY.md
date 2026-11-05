# Security Architecture & Risk Mitigation

## 1. Authentication & Password Security
- **Password Hashing**: Passwords are encrypted using PHP's `password_hash()` with the `PASSWORD_BCRYPT` algorithm. Plaintext passwords are never logged or stored.
- **Session Security**:
  - `httponly`: Set to `true` (cookies inaccessible to JavaScript).
  - `samesite`: Set to `Lax` to prevent Cross-Site Request Forgery via third-party contexts.
  - `secure`: Automatically activated whenever requests arrive over HTTPS.
  - Session Regeneration: Session IDs are regenerated upon authentication (`session_regenerate_id(true)`) to prevent session fixation attacks.

---

## 2. Cross-Site Request Forgery (CSRF) Protection
- State-changing HTTP methods (`POST`, `PUT`, `DELETE`) are guarded by `CSRFMiddleware`.
- Tokens are generated cryptographically using `random_bytes(32)`.
- Validated via constant-time string comparison (`hash_equals`) to prevent timing attacks.
- Both form inputs (`_token`) and AJAX headers (`X-CSRF-TOKEN`) are supported.

---

## 3. SQL Injection & Database Safety
- **Prepared Statements**: 100% of dynamic queries use PDO parameterized prepared statements (`$db->prepare(...)`).
- Raw user input is never concatenated directly into SQL queries.
- Snapshot Integrity: Historical order line items snapshot product names and unit prices at time of creation, preventing historical order tampering if a product's price is edited later.

---

## 4. Cross-Site Scripting (XSS) Mitigation
- HTML escaping helper function `$e()` utilizes `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- HTTP Security Headers enforced on every response:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `X-XSS-Protection: 1; mode=block`
  - `Referrer-Policy: strict-origin-when-cross-origin`

---

## 5. Tenant Isolation & IDOR Defense
- Every query scoped to tenant-owned models includes `business_id = ?`.
- Attempting to access an order, product, or setting belonging to another tenant returns 404 or 403 Forbidden.
- Public customer tracking links rely on 48-character unguessable cryptographic tokens (`tk_...`) rather than incremental database IDs (`/track/tk_948f...` instead of `/orders/123`).

---

## 6. Rate Limiting & Abuse Prevention
- File-backed / in-memory rate limiter (`App\Core\RateLimiter`) throttles token guessing attacks on `/track/{token}` (max 100 requests per minute per IP).
- Failed login attempts are logged in `audit_logs` with client IP and User Agent.
