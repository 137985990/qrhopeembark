# QR Redirect Analytics - Deployment

This is a minimal, composer-less PHP + MySQL site deployed to:

- `/www/wwwroot/OJS/qr-redirect`

## 1) Create database and user

These commands are examples. Run them on the server.

Create DB:

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS qr_redirect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Create user (matches defaults in `config.php`):

```bash
mysql -e "CREATE USER IF NOT EXISTS 'qr_redirect'@'127.0.0.1' IDENTIFIED BY 'change_me_db_pass';"
mysql -e "GRANT ALL PRIVILEGES ON qr_redirect.* TO 'qr_redirect'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

## 2) Import schema

```bash
mysql -uqr_redirect -pchange_me_db_pass qr_redirect < /www/wwwroot/OJS/qr-redirect/sql/schema.sql
```

## 3) Seed counselors

At minimum, you must insert counselor rows so `/r.php?c=<CODE>` can validate codes.

Example seed (from draft list):

```bash
mysql -uqr_redirect -pchange_me_db_pass qr_redirect -e "\
INSERT IGNORE INTO counselors(code,name,active) VALUES
('LVS','Wei Cheng'),
('HBC','Qiantao Shi'),
('BLG','Nanzhu Li'),
('PNL','Shiyou Xu'),
('PRT','Nan Xu'),
('SDG','Ning Gao');\
"
```

To update an existing deployment with the new enable/disable flag safely:

```bash
mysql -uqr_redirect -pchange_me_db_pass qr_redirect -e "SHOW COLUMNS FROM counselors LIKE 'active';"
# If no row is returned, run:
mysql -uqr_redirect -pchange_me_db_pass qr_redirect -e "ALTER TABLE counselors ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER remarks;"
```

## 4) Secure config.php

`config.php` contains DB credentials and the admin username/password.

```bash
chown www:www /www/wwwroot/OJS/qr-redirect/config.php
chmod 640 /www/wwwroot/OJS/qr-redirect/config.php
```

Change the admin password in `/www/wwwroot/OJS/qr-redirect/config.php`:

- `ADMIN_USER` default: `admin`
- `ADMIN_PASS` default: `changeme123`

## 5) Verification commands

Schema import:

```bash
mysql -uqr_redirect -pchange_me_db_pass qr_redirect < /www/wwwroot/OJS/qr-redirect/sql/schema.sql
```

Redirect response:

```bash
curl -i 'https://hopeembark.org/qr-redirect/r.php?c=LVS'
```

Note: this server redirects `http://` to `https://` (and may also redirect `localhost` to the canonical host), so prefer the HTTPS URL above.

Unique count after first hit:

```bash
mysql -uqr_redirect -pchange_me_db_pass qr_redirect -e "select count(*) from unique_counts where counselor_code='LVS'"
```

Clear current test data:

```bash
mysql -uqr_redirect -pchange_me_db_pass qr_redirect -e "TRUNCATE TABLE scans_raw; TRUNCATE TABLE unique_counts;"
```

## Notes

- This relies on PHP being configured on the webserver. If PHP is not installed, install it first (DO NOT run unless explicitly instructed):
  - AlmaLinux 8 example: `dnf install -y php php-mysqlnd`
- `config.php` must not be served as plain text; verify PHP is executing for `.php` requests.
