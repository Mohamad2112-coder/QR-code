# Backup and Disaster Recovery Guide

## 1. Automated Cloud Database Backups
The platform includes an automated backup subsystem (`App\Services\BackupService`):
- **Full Schema & Data Dumps**: Generates complete SQL dumps preserving table structures, keys, constraints, and data records.
- **Retention Policy**: Retains the last 15 backups, automatically pruning older files to avoid disk exhaustion.
- **File Permissions**: SQL dumps are saved in `storage/backups/` with restricted `0640` permissions.

---

## 2. Generating a Backup via CLI
Run:
```bash
php bin/console.php backup
```
Example Output:
```
Backup created successfully: backup_2026_11_04_185925_14ceb01e.sql (41047 bytes)
```

To list existing backup archives:
```bash
php bin/console.php list-backups
```

---

## 3. Database Restore Procedure

### Step 1: Identify the Target Backup File
List backups using `php bin/console.php list-backups` and select the desired file (e.g., `storage/backups/backup_2026_11_04_185925_14ceb01e.sql`).

### Step 2: Execute Restore
Run:
```bash
php bin/console.php restore storage/backups/backup_2026_11_04_185925_14ceb01e.sql
```
Or via MySQL CLI:
```bash
mysql -u root -p qr_pager_saas < storage/backups/backup_2026_11_04_185925_14ceb01e.sql
```

### Step 3: Verify Integrity
Run the automated test suite to confirm database integrity after restoration:
```bash
php tests/run_tests.php
```

---

## 4. Local Business Hub Recovery Procedure
If a Local Business Hub computer crashes or experiences hardware failure:
1. Replace computer and install PHP 8.2+.
2. Copy `local_hub/` folder onto the new machine.
3. Configure `local_hub/config.json` with the original `business_id` and registered `device_uuid`.
4. Run `local_hub/run_hub.bat` (or start `hub_sync_worker.php`).
5. The Hub will immediately pull the cloud product catalog and resume operations.
