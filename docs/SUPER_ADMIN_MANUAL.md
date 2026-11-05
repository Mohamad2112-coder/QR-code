# Platform Super Admin Operations Manual

## 1. Accessing the Platform Super Admin Portal
- URL: `https://app.yourdomain.com/superadmin`
- Default Account: `admin@qrpager.io` / `Password123!`
- The Super Admin portal is completely isolated from tenant business accounts.

---

## 2. Managing Tenant Businesses
From the **Registered Tenant Businesses** panel:
- **View All Workspaces**: Review business name, slug, owner email, total orders processed, and trial expiration dates.
- **Suspend a Tenant**: Click **"Suspend"** to lock a workspace due to expired non-payment or terms of service violation. Suspended tenants cannot create new orders.
- **Activate / Reinstate**: Click **"Activate"** to reinstate a suspended business.

---

## 3. Subscription Plans Configuration
Subscription tiers are dynamically stored in the database (`subscription_plans`), allowing the Super Admin to update prices and quotas without redeploying code:
- **Starter Plan**: Default $19/mo ($190/yr) - Max 500 orders/mo, 5 staff.
- **Professional Plan**: Default $49/mo ($490/yr) - Max 2,500 orders/mo, 15 staff, advanced branding.
- **Premium Plan**: Default $99/mo ($990/yr) - Unlimited orders (0), 50 staff, priority edge sync.

To adjust tier limits:
1. Navigate to **💎 Subscription Plans & Limits**.
2. Modify prices, max order numbers, staff allowances, or device limits.
3. Click **"Update Plan"**.

---

## 4. Manual Payment Verification Workflow
Because initial billing utilizes manual payment verification (bank wire, local remittance, cash at counter):
1. Business submits payment reference note in their billing panel.
2. The payment appears in the **Manual Payment Verification Queue** on the Super Admin dashboard with status `PENDING`.
3. Super Admin verifies the deposit in the company bank account.
4. Click **"Approve & Renew"**:
   - The payment record status transitions to `VERIFIED`.
   - The business's subscription is automatically extended by 1 month (or 12 months for yearly payments).
   - If the business was in `trial` or `suspended`, status transitions immediately to `ACTIVE`.
   - An immutable audit trail entry is logged.
5. If the transfer cannot be validated, click **"Reject"**.

---

## 5. System Backups & Audit Logs
- **Trigger Backup**: Click **"⚡ Trigger Backup Now"** to generate an immediate SQL dump archive.
- **Audit Logs**: Review the chronological list of platform events, including login attempts, staff modifications, subscription upgrades, and backup executions.
