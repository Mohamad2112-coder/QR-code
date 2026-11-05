# Cloud Deployment Guide

## 1. System Requirements
- Operating System: Ubuntu 22.04 LTS / Debian 12 / Rocky Linux 9 or Windows Server
- Web Server: Nginx 1.22+ or Apache 2.4+
- PHP: PHP 8.2 or PHP 8.3 with extensions:
  - `php-fpm`, `php-pdo`, `php-mysql`, `php-curl`, `php-mbstring`, `php-openssl`, `php-json`, `php-gd`, `php-bcmath`
- Database: MySQL 8.0+ or MariaDB 10.4+
- SSL Certificate: Mandatory for HTTPS (Let's Encrypt / Certbot) for Web Push and PWA Service Workers.

---

## 2. Directory Structure & Permissions

Clone or upload project files to `/var/www/qrpager`:
```bash
cd /var/www/qrpager
composer install --no-dev --optimize-autoloader
chmod -R 775 storage
chown -R www-data:www-data storage
```

---

## 3. Environment Configuration

Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```
Configure database credentials and domain:
```ini
APP_NAME="QR Order Pager SaaS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.yourdomain.com

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=qr_pager_saas
DB_USERNAME=qrpager_user
DB_PASSWORD=SecurePasswordHere!

VAPID_SUBJECT="mailto:admin@yourdomain.com"
VAPID_PUBLIC_KEY="YOUR_GENERATED_VAPID_PUBLIC_KEY"
VAPID_PRIVATE_KEY="YOUR_GENERATED_VAPID_PRIVATE_KEY"
```

To generate a new VAPID cryptographic keypair:
```bash
php bin/console.php generate-vapid
```

---

## 4. Database Setup & Migrations

Execute the migrations and seed data:
```bash
php bin/console.php migrate
php bin/console.php seed
```

Default administrator credentials:
- URL: `https://app.yourdomain.com/login`
- Email: `admin@qrpager.io`
- Password: `Password123!`

---

## 5. Nginx Web Server Configuration

Create `/etc/nginx/sites-available/qrpager.conf`:
```nginx
server {
    listen 80;
    server_name app.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name app.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/app.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.yourdomain.com/privkey.pem;

    root /var/www/qrpager/public;
    index index.php;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```
Enable the site:
```bash
ln -s /etc/nginx/sites-available/qrpager.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

---

## 6. Scheduled Cron Jobs

Add the following to `/etc/crontab` or `crontab -e`:
```bash
# Process pending Web Push notification queue every minute
* * * * * www-data php /var/www/qrpager/bin/console.php run-notifications > /dev/null 2>&1

# Automated Daily Database Backup at 03:00 AM
0 3 * * * www-data php /var/www/qrpager/bin/console.php backup > /var/www/qrpager/storage/backup.log 2>&1
```
