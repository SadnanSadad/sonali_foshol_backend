# Sonali Foshol - Backend, Database and Integration Guide

**Status:** Architecture and implementation specification. The Laravel backend and production database do not exist yet. This document is the source of truth for building them.

**Last updated:** 2026-11-19

## Contents

1. [Executive summary](#1-executive-summary)
2. [Locked decisions](#2-locked-decisions)
3. [Current systems](#3-current-systems)
4. [Target architecture](#4-target-architecture)
5. [Technology and versions](#5-technology-and-versions)
6. [PC setup for Windows 11](#6-pc-setup-for-windows-11)
7. [Backend project setup](#7-backend-project-setup)
8. [Database design](#8-database-design)
9. [Demo data import](#9-demo-data-import)
10. [Authentication and sessions](#10-authentication-and-sessions)
11. [Authorization](#11-authorization)
12. [API specification](#12-api-specification)
13. [Business rules](#13-business-rules)
14. [Security](#14-security)
15. [Email and queues](#15-email-and-queues)
16. [Admin frontend integration](#16-admin-frontend-integration)
17. [Storefront integration](#17-storefront-integration-phase-2)
18. [Local development workflow](#18-local-development-workflow)
19. [Testing strategy](#19-testing-strategy)
20. [Production deployment](#20-production-deployment)
21. [Backup and disaster recovery](#21-backup-and-disaster-recovery)
22. [Troubleshooting](#22-troubleshooting)
23. [Phased implementation checklist](#23-phased-implementation-checklist)
24. [Assumptions and open items](#24-assumptions-and-open-items)
25. [Appendix](#25-appendix)

## Repositories

| Repository | Path | Purpose |
|---|---|---|
| Admin | `D:/TheUser/Programming/trial/sonali-storefront-admin` | Staff/owner panel |
| Storefront | `D:/TheUser/Programming/trial/sonali-foshol-storefront` | Customer-facing shop |
| Backend (new) | `D:/TheUser/Programming/trial/sonali-foshol-api` | Laravel API, database, auth, email, file storage |

---

## 1. Executive summary

Build a Laravel + MySQL API that replaces the mock data layer in both React frontends.

The backend will:

- Store products, categories, orders, customers, reviews, calendar events, settings, and admin users in MySQL.
- Serve a staff admin API and a public storefront API.
- Authenticate the admin panel with HttpOnly cookie sessions through Laravel Sanctum, not bearer tokens in `localStorage`.
- Provide a fixed seeded `superadmin` account with a password supplied through environment variables, plus an Artisan command to rotate it.
- Support optional customer accounts while keeping guest checkout as the default path.
- Reserve inventory on order creation and restore it on rejection/cancellation.
- Treat all money totals as server-calculated values. Client-supplied totals are ignored.
- Send email notifications through queued jobs.
- Store uploaded images on Laravel's local public disk and serve them through `storage:link`.
- Import all existing demo data: 29 products, 4 real categories, 20 orders, 10 customers, 18 reviews, 6 calendar events, and merged settings.

Phase 1 completes the backend and admin integration. Phase 2 completes the storefront integration.

---

## 2. Locked decisions

| Area | Decision |
|---|---|
| Backend framework | Laravel latest stable (12 or 13; pin in `composer.lock`) |
| Language | PHP 8.3+ |
| Database | MySQL 8.0, InnoDB, `utf8mb4` |
| Local PHP/Composer | Laravel Herd |
| Local containers | None. Docker is not used because the PC has 7.7 GB RAM |
| Session storage | Database |
| Cache/queue | Database (no Redis required locally) |
| Admin auth | Sanctum SPA cookie session + CSRF, `admin` guard |
| Customer auth | Sanctum SPA cookie session + CSRF, `customer` guard |
| Password hashing | Argon2id |
| Admin 2FA | Optional TOTP with one-time recovery codes |
| Public IDs | ULIDs for public resources |
| Superadmin | One account seeded from env; no public admin registration |
| Admin roles | `superadmin`, `admin` |
| Customer accounts | Optional; guest checkout remains supported |
| Guest order lookup | Order number + phone, rate limited |
| Retroactive guest-order claiming | Not allowed |
| Inventory | Reserve at order creation, restore on reject/cancel, DB transaction + row locks |
| Email | Queued email only; no SMS |
| Images | Laravel local public disk, `storage:link` |
| Public catalog | Merge all 29 demo products |
| Categories | `rice`, `oil`, `honey-ghee`, `spices` (the storefront `all` pseudo-category is not stored) |
| Reviews | 18 total: 10 product-linked + 8 approved general testimonials |
| Settings | Admin values are canonical; storefront-only fields are added |
| Dev topology | API `localhost:8000`, admin `localhost:5173`, storefront `localhost:5174` |
| Production topology | `api.sonalifoshol.com`, `admin.sonalifoshol.com`, `www.sonalifoshol.com` |
| Production cookie domain | `.sonalifoshol.com` |
| Phase 1 | Backend + admin integration |
| Phase 2 | Storefront integration, guest order tracking, optional account UI |

---

## 3. Current systems

### 3.1 Admin panel

- React 19, Vite 6, TypeScript, Tailwind CSS v4, shadcn-style components.
- TanStack Query v5, Zustand, React Hook Form, Zod, Sonner, FullCalendar.
- Mock mode is enabled unless `VITE_USE_MOCK_DATA=false`.
- Current mock API base URL is `http://localhost:4000`.
- Current mock login: `admin@sonalifoshol.com` / `sonali123`.
- Current token storage: `localStorage` key `sfo-admin-token`; Bearer token is attached by the axios request interceptor.
- Current persisted auth key: `sfo-admin-auth`.
- Current mock database key: `sfo-admin-mock-db`.
- Current language key: `sfo-admin-language`.

Current real-mode endpoint paths:

| Module | Current path |
|---|---|
| Login | `POST /auth/login` |
| Me | `GET /auth/me` |
| Logout | `POST /auth/logout` |
| Products | `/admin/products` |
| Product detail | `/admin/products/{id}` |
| Product bulk | `PATCH /admin/products/bulk` |
| Upload | `POST /admin/uploads` |
| Categories | `/admin/categories` |
| Orders | `/admin/orders` |
| Order status | `PATCH /admin/orders/{id}/status` |
| Customers | `/admin/customers` |
| Flag customer | `PATCH /admin/customers/{id}/flag` |
| Reviews | `/admin/reviews` |
| Calendar | `/admin/calendar/events` |
| Settings | `/admin/settings` |
| Dashboard | `/admin/dashboard/summary`, `/sales`, `/status-breakdown`, `/top-products` |

Current domain shapes:

- Product IDs, order IDs, customer IDs, and review IDs are strings.
- Delivery zones are `inside_dhaka` and `outside_dhaka` (underscores).
- Order statuses: `pending`, `confirmed`, `processing`, `shipped`, `delivered`, `rejected`, `cancelled`.
- Payment methods: `cod`, `whatsapp`, `messenger`.
- Admin role type is currently `owner | staff`; this changes to `superadmin | admin`.
- Product weights include `label`, `grams`, `price`, `mrp`, `stock`, `sku`.
- Products include an `images` gallery.
- Reviews require `productId` in the current admin type. This becomes nullable.
- Orders include `discount`, `rejection`, `courier`, and `statusHistory`.

Admin demo data counts:

| File | Count |
|---|---|
| `public/data/products.json` | 20 |
| `public/data/categories.json` | 3 |
| `public/data/orders.json` | 20 |
| `public/data/customers.json` | 10 |
| `public/data/reviews.json` | 10 |
| `public/data/calendar-events.json` | 6 |
| `public/data/settings.json` | 1 |
| `public/data/admin.json` | 1 |

### 3.2 Storefront

- React 19, Vite, TypeScript, Tailwind CSS v4, TanStack Query, axios, Zustand, React Hook Form, Zod, Sonner.
- Package manager: pnpm.
- Mock mode is enabled unless `VITE_USE_MOCK_DATA=false`.
- Current API base URL: `http://localhost:4000`.
- Mock database: `public/data.json`.
- Current mock data: 9 products, 5 categories including the `all` pseudo-category, 8 general reviews.
- Storefront has no login, registration, customer account, or order lookup UI.
- Order receipt is stored in `sessionStorage` under `sonali-foshol:last-order`.

Current storefront endpoint paths:

| Module | Current path |
|---|---|
| Products | `GET /api/products` |
| Product by slug | `GET /api/products/{slug}` |
| Categories | `GET /api/categories` |
| Reviews | `GET /api/reviews` |
| Settings | `GET /api/settings` |
| Create order | `POST /api/orders` |

Storefront domain details:

- Delivery zones are `inside-dhaka` and `outside-dhaka` (hyphens).
- Payment methods: `cod`, `whatsapp`, `messenger`.
- `OrderPayload` currently includes client-calculated `subtotal`, `deliveryFee`, and `total`.
- `CreatedOrderReceipt` extends the payload with `orderId` and `createdAt`.
- Storefront product type requires `nutritionFacts: { labelBn, valueBn }[]`, `cookingTipsBn`, and `weights`.
- Storefront order items include `grams`; admin order items do not.
- Storefront settings keys differ from admin settings keys.

### 3.3 Catalog conflict and resolution

The demo catalogs have zero overlap:

- Admin: 20 products.
- Storefront: 9 products.
- Shared slugs: none.

Resolution: import all 29 products. The unified storefront will show 29 products; the admin product list gains the 9 storefront products.

The storefront has 4 real categories plus `all`:

| Slug | Storefront name | Admin equivalent |
|---|---|---|
| `rice` | ঐতিহ্যবাহী চাল | `rice` |
| `oil` | ঘানি ভাঙা তেল | `oil` |
| `honey-ghee` | খাঁটি মধু ও ঘি | `honey-ghee` |
| `spices` | ঘরে ভাঙা মসলা | Missing; create it |
| `all` | সব পণ্য | Not stored; frontend pseudo-filter |

### 3.4 Review conflict and resolution

- Admin: 10 reviews, each linked to an admin product.
- Storefront: 8 general testimonials with `customerName`, `location`, `rating`, `commentBn`, and no product link.

Resolution:

- Import all 18.
- Make `reviews.product_id` nullable.
- Admin reviews become product-linked.
- Storefront testimonials become approved general reviews with `product_id = NULL`, `author = customerName`, `text = commentBn`, and `location` preserved.
- The admin Reviews page shows general reviews with the existing `reviews.noProduct` label.

### 3.5 Settings conflict and resolution

| Field | Admin demo | Storefront demo | Canonical value |
|---|---|---|---|
| Free shipping threshold | 2000 | 1500 | 2000 |
| Inside Dhaka fee | 60 | 60 | 60 |
| Outside Dhaka fee | 120 | 120 | 120 |
| WhatsApp | 01712345678 | 8801711000000 | 8801712345678 (normalized international digits) |
| Facebook | `facebookUrl` | `facebookPageUrl` | Same URL, mapped |
| Messenger | Same | Same | Same |
| Store name | English + Bangla | Bangla brand name | Admin values |
| Tagline | Missing | Present | Import from storefront |
| Phone display | Missing | Present | Import, aligned to canonical number |
| Email | Missing | `hello@sonalifoshol.com` | Import |
| Address | Missing | Present | Import |
| Low stock threshold | 10 | Missing | 10 |

---

## 4. Target architecture

### 4.1 Topology

```text
                          +------------------------+
                          | MySQL 8 (sonali_foshol)|
                          +------------+-----------+
                                       |
                          +------------v-----------+
                          | Laravel API            |
                          | localhost:8000         |
                          | /api/v1/admin/*        |
                          | /api/v1/auth/*         |
                          | /api/v1/storefront/*   |
                          +------+----------+------+
                                 |          |
             HttpOnly cookie session      HttpOnly cookie session
                                 |          |
                  +--------------v--+    +--v-----------------+
                  | Admin SPA       |    | Storefront SPA     |
                  | localhost:5173  |    | localhost:5174     |
                  +-----------------+    +--------------------+
```

Production:

```text
www.sonalifoshol.com      -> storefront static build
admin.sonalifoshol.com    -> admin static build
api.sonalifoshol.com      -> Laravel API
Cookie domain             -> .sonalifoshol.com
```

### 4.2 Components

| Component | Responsibility |
|---|---|
| Laravel API | Validation, auth, authorization, business rules, persistence, email dispatch |
| MySQL | Source of truth for all business data |
| Local public disk | Product images and uploaded media |
| Database queue | Email jobs and slow work |
| Database cache | Rate limiting, settings cache, miscellaneous cache |
| Admin SPA | Staff operations against `/api/v1/admin/*` |
| Storefront SPA | Public catalog, checkout, customer account, order lookup |

### 4.3 API namespaces

| Prefix | Audience | Auth |
|---|---|---|
| `/api/v1/auth/admin/*` | Admin | Session, mixed public/protected |
| `/api/v1/auth/customer/*` | Storefront customers | Session, mixed public/protected |
| `/api/v1/admin/*` | Admin staff | `auth:admin` |
| `/api/v1/storefront/*` | Public storefront | Public, except customer orders |
| `/sanctum/csrf-cookie` | Both SPAs | Public CSRF bootstrap |

### 4.4 Request flows

Admin login:

1. Admin SPA calls `GET /sanctum/csrf-cookie` with credentials.
2. SPA posts credentials to `/api/v1/auth/admin/login`.
3. Laravel validates the password and checks `is_active`.
4. If 2FA is disabled, Laravel regenerates the session, logs the user into the `admin` guard, and returns the admin user.
5. If 2FA is enabled, Laravel returns a short-lived challenge token and does not authenticate the session.
6. SPA posts the TOTP/recovery code to `/api/v1/auth/admin/2fa/challenge`.
7. Laravel verifies the code, regenerates the session, and logs the user in.
8. SPA calls `/api/v1/auth/admin/me` on reload to restore state.

Storefront order creation:

1. Public storefront reads products and settings from the API.
2. Customer fills checkout.
3. SPA posts customer details, items, zone, payment method, optional email, and an idempotency key.
4. Laravel starts a transaction.
5. Laravel locks the selected product weight rows.
6. Laravel validates product visibility and stock.
7. Laravel recalculates unit prices, line totals, subtotal, delivery fee, and total from the database.
8. Laravel creates or updates the customer by phone.
9. Laravel creates the order and order items with snapshots.
10. Laravel decrements stock.
11. Laravel writes status history.
12. Laravel commits.
13. Laravel returns the authoritative receipt.
14. Queued jobs send emails.

Admin order status change:

1. Admin SPA posts a transition request.
2. Laravel checks the current status and the allowed transition table.
3. Laravel validates rejection/courier fields.
4. In a transaction, Laravel updates the order and inserts history.
5. If the new status is `rejected` or `cancelled`, Laravel restores stock exactly once.
6. Laravel queues a customer email when an email exists.
7. Laravel returns the updated order.

---

## 5. Technology and versions

| Dependency | Version/choice |
|---|---|
| PHP | 8.3 or 8.4 |
| Laravel | Latest stable, pinned |
| MySQL | 8.0 |
| Composer | 2.x |
| Node | 20+ (existing 24 is fine) |
| Admin package manager | npm |
| Storefront package manager | pnpm |
| Sanctum | Laravel Sanctum |
| 2FA | `pragmarx/google2fa` + `bacon/bacon-qr-code` |
| Testing | Pest (preferred) or PHPUnit |
| Code style | Laravel Pint |
| Static analysis | Larastan/PHPStan (recommended) |
| Mail local | `MAIL_MAILER=log`; optional Mailpit |
| Web server local | `php artisan serve` on `localhost:8000` |

Required PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `intl`, `bcmath`, `gd` or `imagick`, `json`, `tokenizer`, `xml`, `ctype`.

---

## 6. PC setup for Windows 11

### 6.1 Machine assumptions

The current machine:

- Windows 11 Pro 10.0.26200.
- 7.7 GB RAM.
- 78.4 GB free disk.
- Git installed.
- Node v24.11.1 installed.
- No PHP, Composer, MySQL, Docker, or Laravel Herd installed.

Minimum recommended for this project:

- Windows 11.
- 8 GB RAM. Close heavy applications while running MySQL, the API, and two Vite dev servers.
- 10 GB free disk for PHP, MySQL, dependencies, and builds.
- Administrator access for service installation.

### 6.2 Install Laravel Herd

Laravel Herd provides PHP, Composer, and the Laravel installer on Windows.

1. Download Herd for Windows from the official Laravel Herd site.
2. Run the installer as administrator.
3. Complete installation.
4. Open Herd and install PHP 8.3 or 8.4.
5. Confirm Herd added PHP and Composer to PATH.
6. Open a new PowerShell window and verify:

```powershell
php -v
composer -V
laravel --version
```

If `php` or `composer` is not found:

- Open Herd settings.
- Enable the PATH integration.
- Restart the terminal.
- Alternatively add Herd's `bin` directory to the user PATH.

Herd is used for PHP and Composer. For local API development, run Laravel with `php artisan serve` on `localhost:8000` rather than a `.test` Herd site. This keeps cookies same-site with `localhost:5173` and `localhost:5174`.

### 6.3 Install MySQL 8

Recommended: MySQL Community Server 8.0 for Windows.

1. Download the MySQL Installer for Windows.
2. Choose a Custom or Server-only installation.
3. Install MySQL Server 8.0.
4. Choose `Development Computer`.
5. Set a strong root password.
6. Keep the default port `3306` unless it is already used.
7. Configure MySQL as a Windows service named `MySQL80`.
8. Add `C:/Program Files/MySQL/MySQL Server 8.0/bin` to the system PATH.
9. Open a new PowerShell window and verify:

```powershell
mysql --version
Get-Service MySQL80
```

Start the service if needed:

```powershell
Start-Service MySQL80
```

### 6.4 Create the database and user

Open MySQL as root:

```powershell
mysql -u root -p
```

Run:

```sql
CREATE DATABASE sonali_foshol
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'sonali'@'localhost'
  IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';

GRANT ALL PRIVILEGES ON sonali_foshol.*
  TO 'sonali'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

Use a different password in real use. Never commit it.

Optional low-memory tuning for 7.7 GB RAM. Create or edit `C:/ProgramData/MySQL/MySQL Server 8.0/my.ini`:

```ini
[mysqld]
innodb_buffer_pool_size=256M
performance_schema=OFF
max_connections=100
```

Restart MySQL after changing it:

```powershell
Restart-Service MySQL80
```

### 6.5 Existing tools

Confirm the following:

```powershell
git --version
node --version
npm --version
pnpm --version
```

If pnpm is missing:

```powershell
npm install -g pnpm
```

Enable Git long paths:

```powershell
git config --system core.longpaths true
```

### 6.6 Optional tools

| Tool | Purpose |
|---|---|
| VS Code | Editor |
| PHP Intelephense | PHP intelligence |
| Laravel extension | Blade/routes/artisan helpers |
| SQLTools or MySQL Workbench | Database inspection |
| Postman, Insomnia, or Thunder Client | API testing |
| Mailpit | Local email inspection |
| 7-Zip | Backups/archives |

Optional Mailpit local ports: SMTP `1025`, web UI `8025`. If Mailpit is not installed, use `MAIL_MAILER=log`.

### 6.7 Ports

| Service | Port |
|---|---|
| Laravel API | 8000 |
| Admin Vite | 5173 |
| Storefront Vite | 5174 |
| MySQL | 3306 |
| Mailpit SMTP | 1025 |
| Mailpit UI | 8025 |
| Herd/nginx | 80/443 if Herd sites are enabled |

Check port usage:

```powershell
Get-NetTCPConnection -State Listen |
  Where-Object { $_.LocalPort -in 8000,5173,5174,3306,1025,8025 } |
  Select-Object LocalAddress,LocalPort,OwningProcess
```

Kill a process by ID:

```powershell
Stop-Process -Id <PID>
```

### 6.8 Windows-specific notes

- Use `localhost`, not `127.0.0.1`, consistently. Cookies set for `localhost` are not automatically shared with `127.0.0.1`.
- Do not use a Herd `.test` domain during Phase 1 unless the frontends are also served from `.test` domains. Cross-site cookies and SameSite rules add unnecessary complexity.
- Windows Application Control currently blocks the oxlint native binding in the admin project. This is an environment restriction. Use TypeScript typechecking, builds, and browser smoke tests for verification.
- Keep antivirus exclusions only for project and database folders if performance is poor. Do not disable security software globally.
- If MySQL will not start, check `C:/ProgramData/MySQL/MySQL Server 8.0/Data/*.err`.

---

## 7. Backend project setup

### 7.1 Create the project

From `D:/TheUser/Programming/trial`:

```powershell
laravel new sonali-foshol-api --database=mysql
```

If the Laravel installer is unavailable:

```powershell
composer create-project laravel/laravel sonali-foshol-api
```

Enter the project:

```powershell
Set-Location "D:/TheUser/Programming/trial/sonali-foshol-api"
```

### 7.2 Install packages

```powershell
composer require laravel/sanctum
composer require pragmarx/google2fa bacon/bacon-qr-code
composer require --dev pestphp/pest larastan/larastan
```

Run Sanctum's installer if the Laravel version provides it:

```powershell
php artisan install:api
```

If `install:api` is unavailable, publish Sanctum's migrations manually:

```powershell
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### 7.3 Initial configuration

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Edit `.env` using the template in section 7.4. Create the public storage link:

```powershell
php artisan storage:link
```

Run migrations and seeders after the schema and seeders are implemented:

```powershell
php artisan migrate --seed
```

Start the API:

```powershell
php artisan serve --host=localhost --port=8000
```

The API base URL is `http://localhost:8000`.

### 7.4 `.env` template

Never commit `.env`. Commit a sanitized `.env.example`.

```dotenv
APP_NAME="Sonali Foshol API"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost:8000

APP_LOCALE=bn
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sonali_foshol
DB_USERNAME=sonali
DB_PASSWORD=REPLACE_WITH_DATABASE_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_DOMAIN=localhost
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true

CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=public

SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:5174
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:5174

FRONTEND_ADMIN_URL=http://localhost:5173
FRONTEND_STOREFRONT_URL=http://localhost:5174

SUPERADMIN_NAME="Sonali Foshol Owner"
SUPERADMIN_EMAIL=admin@sonalifoshol.com
SUPERADMIN_USERNAME=admin
SUPERADMIN_PASSWORD=CHANGE_ME_LOCAL_DEMO_PASSWORD

MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@sonalifoshol.com
MAIL_FROM_NAME="${APP_NAME}"

# Optional Mailpit instead of log:
# MAIL_MAILER=smtp
# MAIL_HOST=127.0.0.1
# MAIL_PORT=1025
# MAIL_USERNAME=null
# MAIL_PASSWORD=null
# MAIL_ENCRYPTION=null
```

Local demo parity option: set `SUPERADMIN_PASSWORD=sonali123` only for local development, then change it before any shared or production use.

Production `.env` must use:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sonalifoshol.com
SESSION_DOMAIN=.sonalifoshol.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=admin.sonalifoshol.com,www.sonalifoshol.com,sonalifoshol.com
CORS_ALLOWED_ORIGINS=https://admin.sonalifoshol.com,https://www.sonalifoshol.com,https://sonalifoshol.com
```

### 7.5 Auth guards

`config/auth.php`:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'admin' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'customer' => [
        'driver' => 'session',
        'provider' => 'customers',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
    'customers' => [
        'driver' => 'eloquent',
        'model' => App\Models\Customer::class,
    ],
],

'passwords' => [
    'users' => [
        'provider' => 'users',
        'table' => 'password_reset_tokens',
        'expire' => 60,
        'throttle' => 60,
    ],
    'customers' => [
        'provider' => 'customers',
        'table' => 'customer_password_reset_tokens',
        'expire' => 60,
        'throttle' => 60,
    ],
],
```

Hashing: set `HASH_DRIVER=argon2id` if the environment supports it, or set the default hasher in `config/hashing.php` to `argon2id`.

### 7.6 Sanctum and CORS

`config/sanctum.php`:

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost:5173,localhost:5174')),
'guard' => ['web'],
'expiration' => null,
'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
```

Use session guards (`auth:admin`, `auth:customer`) on routes. Apply Sanctum's stateful middleware so CSRF and session cookies are configured for the SPA origins.

`config/cors.php`:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174')),
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

Never use `allowed_origins => ['*']` with credentials.

### 7.7 Suggested backend structure

```text
sonali-foshol-api/
  app/
    Console/Commands/
      RotateAdminPassword.php
      ImportDemoData.php
    Enums/
      AdminRole.php
      OrderStatus.php
      PaymentMethod.php
      DeliveryZone.php
      ReviewStatus.php
      ProductStatus.php
    Http/
      Controllers/Api/V1/Admin/
      Controllers/Api/V1/Auth/
      Controllers/Api/V1/Storefront/
      Middleware/
      Requests/Admin/
      Requests/Auth/
      Requests/Storefront/
      Resources/Admin/
      Resources/Storefront/
    Jobs/
    Mail/
    Models/
    Policies/
    Services/
      OrderService.php
      InventoryService.php
      SettingsService.php
      OrderNumberService.php
    Support/
  config/
  database/
    factories/
    migrations/
    seeders/
      data/
        admin/
        storefront.json
      DemoDataSeeder.php
  docs/
    BACKEND-GUIDE.md
  public/
  resources/views/mail/
  routes/
    api.php
    console.php
  storage/
  tests/
```

---

## 8. Database design

### 8.1 Conventions

- Engine: InnoDB.
- Character set: `utf8mb4`.
- Collation: `utf8mb4_unicode_ci`.
- Timestamps: UTC in the database; API returns ISO 8601 UTC strings; frontends format for display.
- Money: unsigned integers in BDT. No floating point.
- Public primary keys: ULID `char(26)`.
- Internal relation tables: unsigned `bigint` auto-increment.
- Soft deletes: products only. Order items preserve snapshots even if a product is deleted.
- Foreign keys: `products.category_id` restricts deletion; weights/images cascade; order customer and item product/weight are null on delete; review product and calendar order are null on delete; audit user is null on delete.
- Enums are stored as strings and validated/cast by application enums. Avoid MySQL `ENUM` so values can evolve without table rebuilds.
- JSON columns: `nutrition_highlights`, `nutrition_facts`, `audit_logs.changes`.

### 8.2 Entity relationships

```text
users 1---* audit_logs
users 1---* order_status_history

customers 1---* orders
customers 1---* customer_password_reset_tokens

categories 1---* products
products 1---* product_images
products 1---* product_weights
products 1---* reviews

orders 1---* order_items
orders 1---* order_status_history
orders 1---* calendar_events (optional delivery events)
orders has optional unique client_request_id for idempotency

settings is a singleton row
sessions, cache, jobs, failed_jobs are infrastructure tables
```

### 8.3 Table details

#### `users`

Admin accounts. No public registration.

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `name` | `varchar(120)` | required |
| `email` | `varchar(190)` | required, unique, lowercase |
| `username` | `varchar(60)` | nullable, unique |
| `password` | `varchar(255)` | required, Argon2id hash |
| `role` | `varchar(20)` | `superadmin` or `admin`, index |
| `avatar` | `varchar(500)` | nullable |
| `two_factor_secret` | `text` | nullable, encrypted |
| `two_factor_recovery_codes` | `text` | nullable, encrypted |
| `two_factor_confirmed_at` | `timestamp` | nullable |
| `last_login_at` | `timestamp` | nullable |
| `is_active` | `boolean` | default true, index |
| `remember_token` | `varchar(100)` | nullable |
| `created_at`, `updated_at` | `timestamp` | required |

Rules:

- A deactivated user cannot log in.
- Role changes and password changes should revoke sessions.
- The seeded superadmin is created only if absent.
- Never expose the password hash, 2FA secret, or recovery codes.

#### `customers`

Guest/customer identity. Guest checkout creates rows. Optional accounts add email/password.

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `name` | `varchar(120)` | required |
| `phone` | `varchar(20)` | required, unique, indexed |
| `email` | `varchar(190)` | nullable, unique when present |
| `password` | `varchar(255)` | nullable; null means guest-only |
| `email_verified_at` | `timestamp` | nullable |
| `district` | `varchar(120)` | required |
| `address` | `text` | required |
| `flagged` | `boolean` | default false, index |
| `remember_token` | `varchar(100)` | nullable |
| `last_order_at` | `timestamp` | nullable, index |
| `created_at`, `updated_at` | `timestamp` | required |

`orderCount` and `totalSpent` are calculated from `orders`, not stored, to avoid drift.

#### `password_reset_tokens`

| Column | Type | Rules |
|---|---|---|
| `email` | `varchar(190)` | PK |
| `token` | `varchar(255)` | hashed |
| `created_at` | `timestamp` | nullable |

#### `customer_password_reset_tokens`

Same shape as `password_reset_tokens`, used by the `customers` password broker.

#### `sessions`

Standard Laravel database session table.

| Column | Type | Rules |
|---|---|---|
| `id` | `varchar(255)` | PK |
| `user_id` | `char(26)` | nullable, index |
| `ip_address` | `varchar(45)` | nullable |
| `user_agent` | `text` | nullable |
| `payload` | `longText` | required |
| `last_activity` | `int` | index |

#### `personal_access_tokens`

Optional, created by Sanctum. Cookie sessions are the primary browser auth. Tokens may be used for CLI/testing only. Do not store tokens in frontend `localStorage`.

#### `categories`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `slug` | `varchar(120)` | required, unique |
| `name_en` | `varchar(120)` | required |
| `name_bn` | `varchar(120)` | required |
| `sort_order` | `unsignedSmallInteger` | default 0 |
| `hidden` | `boolean` | default false |
| `created_at`, `updated_at` | `timestamp` | required |

Indexes: `(hidden, sort_order)`.

#### `products`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `category_id` | `char(26)` | FK categories, restrict |
| `slug` | `varchar(160)` | required, unique |
| `name_bn` | `varchar(200)` | required |
| `name_en` | `varchar(200)` | nullable |
| `origin_bn` | `varchar(200)` | nullable |
| `short_desc_bn` | `text` | required |
| `full_desc_bn` | `longText` | required |
| `image_path` | `varchar(500)` | required |
| `badge_bn` | `varchar(120)` | nullable |
| `in_stock` | `boolean` | default true |
| `status` | `varchar(20)` | `active` or `draft`, default active |
| `nutrition_highlights` | `json` | nullable array of strings |
| `nutrition_facts` | `json` | nullable array of `{labelBn, valueBn}` |
| `cooking_tips_bn` | `text` | nullable |
| `created_at`, `updated_at` | `timestamp` | required |
| `deleted_at` | `timestamp` | nullable, soft delete |

Indexes: `status`, `(category_id, status)`, `(status, in_stock)`, `slug`.

Public availability is computed:

```text
available = status = active
            and in_stock = true
            and sum(product_weights.stock) > 0
```

#### `product_images`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `product_id` | `char(26)` | FK products, cascade |
| `path` | `varchar(500)` | required |
| `sort_order` | `unsignedSmallInteger` | default 0 |
| `created_at`, `updated_at` | `timestamp` | required |

#### `product_weights`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `product_id` | `char(26)` | FK products, cascade |
| `label` | `varchar(80)` | required, e.g. `১ কেজি` |
| `grams` | `unsignedInteger` | required |
| `price` | `unsignedInteger` | required, BDT |
| `mrp` | `unsignedInteger` | required, BDT |
| `stock` | `unsignedInteger` | default 0 |
| `sku` | `varchar(80)` | nullable, unique |
| `sort_order` | `unsignedSmallInteger` | default 0 |
| `created_at`, `updated_at` | `timestamp` | required |

Unique: `(product_id, grams)`, `sku`. Index: `product_id`.

#### `orders`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `order_number` | `varchar(40)` | required, unique |
| `client_request_id` | `char(36)` | nullable, unique; idempotency key |
| `customer_id` | `char(26)` | nullable FK customers, null on delete |
| `status` | `varchar(20)` | default pending |
| `payment_method` | `varchar(20)` | `cod`, `whatsapp`, `messenger` |
| `delivery_zone` | `varchar(20)` | `inside_dhaka`, `outside_dhaka` |
| `customer_name` | `varchar(120)` | snapshot |
| `customer_phone` | `varchar(20)` | snapshot, index |
| `customer_email` | `varchar(190)` | nullable, snapshot |
| `district` | `varchar(120)` | snapshot |
| `address` | `text` | snapshot |
| `note` | `text` | nullable |
| `subtotal` | `unsignedInteger` | server-calculated |
| `delivery_fee` | `unsignedInteger` | server-calculated |
| `discount` | `unsignedInteger` | default 0 |
| `total` | `unsignedInteger` | server-calculated |
| `expected_delivery_date` | `timestamp` | nullable |
| `placed_by` | `varchar(20)` | `guest`, `customer`, `admin` |
| `rejection_reason` | `varchar(40)` | nullable |
| `rejection_feedback` | `text` | nullable |
| `rejected_by` | `varchar(120)` | nullable |
| `rejected_at` | `timestamp` | nullable |
| `courier_name` | `varchar(120)` | nullable |
| `courier_tracking_id` | `varchar(120)` | nullable |
| `stock_restored_at` | `timestamp` | nullable; idempotency guard |
| `ip_address` | `varchar(45)` | nullable |
| `created_at`, `updated_at` | `timestamp` | required |

Indexes: `status`, `created_at`, `(status, created_at)`, `customer_phone`, `customer_id`, `order_number`.

Order number format: `SFO-YYYY-NNNN`, e.g. `SFO-2026-8492`. Use a collision-safe generator and retry on duplicate.

#### `order_items`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `order_id` | `char(26)` | FK orders, cascade |
| `product_id` | `char(26)` | nullable FK products, null on delete |
| `product_weight_id` | `bigint unsigned` | nullable FK product_weights, null on delete |
| `product_slug` | `varchar(160)` | snapshot |
| `product_name_bn` | `varchar(200)` | snapshot |
| `weight_label` | `varchar(80)` | snapshot |
| `grams` | `unsignedInteger` | snapshot |
| `unit_price` | `unsignedInteger` | snapshot |
| `qty` | `unsignedInteger` | required, min 1 |
| `line_total` | `unsignedInteger` | `unit_price * qty` |
| `image_path` | `varchar(500)` | nullable snapshot |
| `created_at`, `updated_at` | `timestamp` | required |

Indexes: `order_id`, `product_id`.

#### `order_status_history`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `order_id` | `char(26)` | FK orders, cascade |
| `status` | `varchar(20)` | required |
| `note` | `text` | nullable |
| `changed_by_user_id` | `char(26)` | nullable FK users, null on delete |
| `changed_by_name` | `varchar(120)` | default `System` |
| `created_at` | `timestamp` | required |

Index: `(order_id, created_at)`.

#### `reviews`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `product_id` | `char(26)` | nullable FK products, null on delete |
| `product_name_bn` | `varchar(200)` | nullable snapshot |
| `author` | `varchar(120)` | required |
| `location` | `varchar(160)` | nullable; used by storefront testimonials |
| `rating` | `unsignedTinyInteger` | 1-5 |
| `text` | `text` | required |
| `status` | `varchar(20)` | `pending`, `approved`, `hidden`; default pending |
| `created_at`, `updated_at` | `timestamp` | required |

Indexes: `status`, `(status, product_id)`, `created_at`.

#### `calendar_events`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` | PK, ULID |
| `title` | `varchar(200)` | required |
| `type` | `varchar(20)` | `admin` or `delivery` |
| `start_at` | `timestamp` | required |
| `end_at` | `timestamp` | nullable |
| `all_day` | `boolean` | default false |
| `note` | `text` | nullable |
| `order_id` | `char(26)` | nullable FK orders, null on delete |
| `created_at`, `updated_at` | `timestamp` | required |

Indexes: `start_at`, `end_at`, `type`.

#### `settings`

Single row, `id = 1`.

| Column | Type | Default |
|---|---|---|
| `id` | `unsignedTinyInteger` | PK, 1 |
| `store_name` | `varchar(160)` | Sonali Foshol Organics |
| `store_name_bn` | `varchar(160)` | সোনালী ফসল অর্গানিকস |
| `brand_tagline_bn` | `varchar(255)` | খাঁটি গ্রামের খাবার, সরাসরি কৃষকের কাছ থেকে |
| `whatsapp_number` | `varchar(20)` | 8801712345678 |
| `facebook_url` | `varchar(500)` | https://facebook.com/sonalifoshol |
| `messenger_url` | `varchar(500)` | https://m.me/sonalifoshol |
| `delivery_fee_inside` | `unsignedInteger` | 60 |
| `delivery_fee_outside` | `unsignedInteger` | 120 |
| `free_shipping_threshold` | `unsignedInteger` | 2000 |
| `low_stock_threshold` | `unsignedInteger` | 10 |
| `phone_display` | `varchar(40)` | +৮৮০ ১৭১২-৩৪৫৬৭৮ |
| `email` | `varchar(190)` | hello@sonalifoshol.com |
| `address_bn` | `varchar(255)` | চিরিরবন্দর, দিনাজপুর, বাংলাদেশ |
| `inside_delivery_days` | `unsignedTinyInteger` | 2 |
| `outside_delivery_days` | `unsignedTinyInteger` | 4 |
| `created_at`, `updated_at` | `timestamp` | required |

Admin API returns admin field names. Public API returns storefront field names:

| Public key | Database column |
|---|---|
| `brandNameBn` | `store_name_bn` |
| `brandTaglineBn` | `brand_tagline_bn` |
| `whatsappNumber` | `whatsapp_number` |
| `facebookPageUrl` | `facebook_url` |
| `messengerUrl` | `messenger_url` |
| `insideDhakaDelivery` | `delivery_fee_inside` |
| `outsideDhakaDelivery` | `delivery_fee_outside` |
| `freeShippingThreshold` | `free_shipping_threshold` |
| `phoneDisplay` | `phone_display` |
| `email` | `email` |
| `addressBn` | `address_bn` |

#### `audit_logs`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `user_id` | `char(26)` | nullable FK users, null on delete |
| `action` | `varchar(80)` | e.g. `admin.login`, `product.updated` |
| `subject_type` | `varchar(120)` | nullable |
| `subject_id` | `varchar(40)` | nullable |
| `changes` | `json` | nullable, redacted |
| `ip_address` | `varchar(45)` | nullable |
| `user_agent` | `text` | nullable |
| `created_at` | `timestamp` | required |

Indexes: `user_id`, `action`, `(subject_type, subject_id)`, `created_at`.

Never store passwords, tokens, 2FA secrets, or full session payloads in audit logs.

#### `email_logs`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `to_email` | `varchar(190)` | required |
| `subject` | `varchar(255)` | required |
| `template` | `varchar(120)` | required |
| `status` | `varchar(20)` | `queued`, `sent`, `failed` |
| `error` | `text` | nullable |
| `order_id` | `char(26)` | nullable |
| `sent_at` | `timestamp` | nullable |
| `created_at`, `updated_at` | `timestamp` | required |

#### Infrastructure tables

Laravel defaults: `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

### 8.4 Key indexes and constraints

- `users.email`, `users.username`, `customers.phone`, `customers.email`.
- `products.slug`, `products.status`, `(products.category_id, products.status)`.
- `product_weights`: unique `(product_id, grams)`, unique `sku`.
- `orders.order_number`, unique `client_request_id`, `(status, created_at)`, `customer_phone`.
- `order_items.order_id`.
- `reviews.status`, `(status, product_id)`.
- `calendar_events.start_at`, `end_at`.
- `audit_logs` user/action/subject/date.
- Foreign keys as described in section 8.1.
- Check constraints may be added for `rating BETWEEN 1 AND 5`, non-negative money, and `mrp >= price`; application validation remains required.

### 8.5 Timezone and date handling

- Store UTC.
- API returns ISO 8601 with `Z`.
- Admin/storefront format with `Asia/Dhaka`.
- Dashboard "today" and sales ranges use `Asia/Dhaka`, converted to UTC for queries.
- Calendar all-day events use midnight Asia/Dhaka converted to UTC.

### 8.6 Migration order

1. `users`
2. `password_reset_tokens`
3. `sessions`
4. `cache`, `cache_locks`
5. `jobs`, `job_batches`, `failed_jobs`
6. `customers`
7. `customer_password_reset_tokens`
8. `categories`
9. `products`
10. `product_images`
11. `product_weights`
12. `orders`
13. `order_items`
14. `order_status_history`
15. `reviews`
16. `calendar_events`
17. `settings`
18. `audit_logs`
19. `email_logs`
20. Sanctum `personal_access_tokens`

---

## 9. Demo data import

### 9.1 Source files

Copy the sources into the backend so seeders do not depend on sibling repositories at runtime:

```text
database/seeders/data/
  admin/
    admin.json
    products.json
    categories.json
    orders.json
    customers.json
    reviews.json
    calendar-events.json
    settings.json
  storefront.json
```

The storefront `public/data.json` becomes `storefront.json`. Its structure is:

```json
{
  "storeSettings": {},
  "categories": [],
  "products": [],
  "reviews": []
}
```

Copy images into `storage/app/public/products/`:

- Admin product images and referenced gallery images.
- Storefront product SVGs.
- Storefront placeholder/logo/hero images remain frontend assets and do not need to be in the API unless used by product records.

### 9.2 Import order

1. Settings.
2. Categories.
3. Products and image records.
4. Product weights.
5. Customers.
6. Orders.
7. Order items.
8. Order status history.
9. Reviews.
10. Calendar events.
11. Superadmin.

Use database transactions where practical and make each importer idempotent by natural keys.

### 9.3 Categories

Import:

- `cat-01` rice.
- `cat-02` oil.
- `cat-03` honey-ghee.
- New `spices`: `name_en = Spices`, `name_bn = ঘরে ভাঙা মসলা`, `sort_order = 4`.
- Skip `all`.

Expected: 4 categories.

### 9.4 Products

Import all 20 admin products and all 9 storefront products.

Product field mapping:

| API field | Database |
|---|---|
| `id` | New ULID |
| `slug` | `products.slug` |
| `category` | Resolve to `category_id` |
| `nameBn` | `name_bn` |
| `nameEn` | `name_en` |
| `originBn` | `origin_bn` |
| `image` | `image_path` |
| `images` | `product_images` |
| `badgeBn` | `badge_bn` |
| `inStock` | `in_stock` |
| `status` | `status` (`active` if absent) |
| `shortDescBn` | `short_desc_bn` |
| `fullDescBn` | `full_desc_bn` |
| `nutritionHighlights` | `nutrition_highlights` JSON |
| `nutritionFacts` | `nutrition_facts` JSON |
| `cookingTipsBn` | `cooking_tips_bn` |
| `weights[].label` | `product_weights.label` |
| `weights[].grams` | `product_weights.grams` |
| `weights[].price` | `product_weights.price` |
| `weights[].mrp` | `product_weights.mrp` |
| `weights[].stock` | `product_weights.stock` |
| `weights[].sku` | `product_weights.sku` |

Rules:

- Admin products already have stock and SKU values.
- Storefront products have no stock or SKU. Assign `stock = 50` to each storefront variant and generate `sku = <product-slug>-<grams>`.
- Admin products have no `nutritionFacts` or `cookingTipsBn` in the current data. Seed them as `[]` and `NULL`. The admin UI allows editing after the schema is extended.
- Storefront products keep their nutrition facts and cooking tips.
- Storefront products use `status = active`.
- Product IDs change to ULIDs. All references in orders and reviews must be resolved by slug, not by old IDs.
- Keep original slugs exactly.

Expected: 29 products.

### 9.5 Images

- Use storage paths such as `products/<filename>`.
- API resources return a full URL such as `http://localhost:8000/storage/products/<filename>` locally, or `https://api.sonalifoshol.com/storage/products/<filename>` in production.
- Do not store absolute localhost URLs in the database.
- Seed SVGs are trusted demo assets. Uploaded files must be jpg/png/webp only.
- Copy images before creating records so any missing image is detected during import.

### 9.6 Customers

Import all 10 admin customers.

| JSON | Database |
|---|---|
| `id` | New ULID |
| `name` | `name` |
| `phone` | `phone` |
| `district` | `district` |
| `address` | `address` |
| `flagged` | `flagged` |
| `createdAt` | `created_at` |
| `lastOrderAt` | `last_order_at` |
| `orderCount` | Not stored |
| `totalSpent` | Not stored |

`orderCount`, `totalSpent`, and `lastOrderAt` are recalculated from imported orders. If the JSON aggregate differs from the order data, the order data wins. The admin UI may show slightly different totals than the old mock.

### 9.7 Orders

Import all 20 admin orders.

| JSON | Database |
|---|---|
| `id` | New ULID |
| `orderNumber` | `order_number` |
| `createdAt` | `created_at` |
| `expectedDeliveryDate` | `expected_delivery_date` |
| `status` | `status` |
| `customer.name` | `customer_name` plus linked customer |
| `customer.phone` | `customer_phone` |
| `customer.district` | `district` |
| `customer.address` | `address` |
| `customer.note` | `note` |
| `deliveryZone` | `delivery_zone` |
| `paymentMethod` | `payment_method` |
| `subtotal` | `subtotal` |
| `deliveryFee` | `delivery_fee` |
| `discount` | `discount` |
| `total` | `total` |
| `items[].productId` | Resolve by item slug; store new product ULID |
| `items[].slug` | `product_slug` |
| `items[].title` | `product_name_bn` |
| `items[].image` | `image_path` |
| `items[].weightLabel` | `weight_label` |
| `items[].unitPrice` | `unit_price` |
| `items[].qty` | `qty` |
| `statusHistory[]` | `order_status_history` |
| `rejection` | Rejection columns |
| `courier` | Courier columns |

Rules:

- Link to customers by phone. If no customer exists, create one from the order snapshot.
- Store item snapshots even if a product is missing.
- Import historic orders without decrementing current stock. Current stock is treated as the current state after demo orders.
- Preserve order numbers and statuses.
- `placed_by = customer` for imported orders with a linked customer.
- Preserve `createdAt` and history timestamps.
- Ensure `total = subtotal + delivery_fee - discount` unless the demo values are intentionally inconsistent. Record any inconsistency in the import report; do not silently alter demo totals.

Expected: 20 orders.

### 9.8 Reviews

Admin reviews:

- Map `productId` through the admin product JSON to the new product ULID.
- Preserve `productNameBn`, `author`, `rating`, `text`, `status`, and `createdAt`.
- `location` is `NULL`.

Storefront testimonials:

- `product_id = NULL`.
- `product_name_bn = NULL`.
- `author = customerName`.
- `location` preserved.
- `rating` preserved.
- `text = commentBn`.
- `status = approved`.
- `createdAt`: storefront data has no date. Assign deterministic dates in the same demo period, for example 2026-10-01 through 2026-10-08.

Expected: 18 reviews (10 product-linked, 8 general).

### 9.9 Calendar events

Import 6 events.

- `id` new ULID.
- `title`, `type`, `start`, `end`, `allDay`, `note` mapped.
- If an event has `orderId`, resolve it to the imported order ULID. Only import the stored admin calendar file to avoid duplicate generated delivery events.
- Preserve event dates.

Expected: 6 calendar events.

### 9.10 Settings

Seed one row:

```text
store_name: Sonali Foshol Organics
store_name_bn: সোনালী ফসল অর্গানিকস
brand_tagline_bn: খাঁটি গ্রামের খাবার, সরাসরি কৃষকের কাছ থেকে
whatsapp_number: 8801712345678
facebook_url: https://facebook.com/sonalifoshol
messenger_url: https://m.me/sonalifoshol
delivery_fee_inside: 60
delivery_fee_outside: 120
free_shipping_threshold: 2000
low_stock_threshold: 10
phone_display: +৮৮০ ১৭১২-৩৪৫৬৭৮
email: hello@sonalifoshol.com
address_bn: চিরিরবন্দর, দিনাজপুর, বাংলাদেশ
inside_delivery_days: 2
outside_delivery_days: 4
```

The WhatsApp number is normalized to international format without `+` because the storefront builds `https://wa.me/<digits>`.

### 9.11 Superadmin

Read from env:

```text
SUPERADMIN_NAME
SUPERADMIN_EMAIL
SUPERADMIN_USERNAME
SUPERADMIN_PASSWORD
```

Create only if the email does not exist. Do not overwrite an existing password on every seed. Use the rotation command instead.

Local demo parity:

```text
email: admin@sonalifoshol.com
password: sonali123
```

This is local-only. Change it before sharing, deploying, or exposing the admin panel.

### 9.12 Seed verification

After seeding:

```sql
SELECT COUNT(*) FROM categories;                       -- 4
SELECT COUNT(*) FROM products;                         -- 29
SELECT COUNT(*) FROM product_weights;                  -- sum of all variants
SELECT COUNT(*) FROM customers;                        -- 10
SELECT COUNT(*) FROM orders;                           -- 20
SELECT COUNT(*) FROM reviews;                          -- 18
SELECT COUNT(*) FROM reviews WHERE product_id IS NULL; -- 8
SELECT COUNT(*) FROM calendar_events;                  -- 6
SELECT COUNT(*) FROM settings;                         -- 1
SELECT COUNT(*) FROM users WHERE role = 'superadmin';  -- 1
```

### 9.13 Reset and import commands

Recommended:

```powershell
php artisan migrate:fresh --seed
```

Add a wrapper command:

```powershell
php artisan demo:import --fresh
```

The command should:

- Refuse to run in production unless `--force`.
- Report counts and skipped records.
- Be safe to re-run without duplicates.
- Copy images from the bundled seed assets when missing.

---

## 10. Authentication and sessions

### 10.1 Password storage

- Use Argon2id.
- Do not log passwords.
- Password policy: minimum 12 characters for admins; customer passwords minimum 8.
- Password confirmation is required on reset and change endpoints.
- Compromised-password checks are optional.

### 10.2 Admin login

`POST /api/v1/auth/admin/login`

Request:

```json
{
  "login": "admin@sonalifoshol.com",
  "password": "sonali123",
  "remember": false
}
```

`login` accepts an email or username. The SPA may send `email` for backward compatibility.

Success without 2FA:

```json
{
  "success": true,
  "data": {
    "user": {
      "id": "01J...",
      "name": "Sonali Foshol Owner",
      "email": "admin@sonalifoshol.com",
      "username": "admin",
      "role": "superadmin",
      "avatar": null
    }
  }
}
```

Success with 2FA required:

```json
{
  "success": true,
  "data": {
    "two_factor_required": true,
    "challenge_token": "short-lived-encrypted-token"
  }
}
```

Failure:

```json
{
  "success": false,
  "message": "Invalid credentials."
}
```

Use the same generic message for unknown user, wrong password, and inactive account to reduce enumeration.

Rules:

- Rate limit by normalized login and IP.
- Regenerate the session after successful auth.
- Update `last_login_at`.
- Audit success and failure.
- Never return the password hash or 2FA secret.

### 10.3 Admin 2FA

Enable flow:

1. Authenticated admin requests `POST /api/v1/auth/admin/2fa/enable`.
2. Server generates a TOTP secret and recovery codes.
3. Server stores the secret encrypted and recovery codes encrypted, but does not mark confirmed.
4. Server returns the secret, an SVG QR code, and the recovery codes once.
5. Admin scans the QR code and posts a code to `POST /api/v1/auth/admin/2fa/confirm`.
6. Server verifies the code and sets `two_factor_confirmed_at`.

Login flow with 2FA:

1. Password validation succeeds.
2. Server issues a short-lived challenge token tied to the user and IP.
3. Server does not log the user in.
4. Admin posts `{challenge_token, code}` to `/api/v1/auth/admin/2fa/challenge`.
5. Server verifies TOTP or a one-time recovery code.
6. Recovery codes are invalidated after use.
7. Server logs in the user and regenerates the session.

Disable flow:

- Requires the current password.
- Requires a valid TOTP or recovery code.
- Clears the secret, recovery codes, and confirmation timestamp.
- Audits the change.

Regeneration flow:

- Requires the current password.
- Returns a new set of recovery codes once.
- Invalidates old codes.

### 10.4 Customer auth

Registration:

`POST /api/v1/auth/customer/register`

```json
{
  "name": "Customer Name",
  "phone": "01711234567",
  "email": "customer@example.com",
  "password": "strong-password",
  "password_confirmation": "strong-password"
}
```

Rules:

- Phone must be unique in customers.
- Email must be unique when present.
- If a guest customer with the same phone exists, do not silently attach an account. For Phase 2, reject registration when the phone already exists and require support-assisted verification.
- Do not retroactively attach old orders to a new account.
- New orders placed while logged in are linked.
- Guest orders remain accessible by order number + phone.

Login:

`POST /api/v1/auth/customer/login`

```json
{
  "email": "customer@example.com",
  "password": "strong-password"
}
```

Customer session uses the `customer` guard. Admin and customer sessions may coexist in one browser session because they use different guard keys, but logging out invalidates the shared session. Document this behavior.

### 10.5 CSRF

- Both SPAs must call `GET /sanctum/csrf-cookie` with credentials before the first non-GET request.
- Axios must send `X-XSRF-TOKEN` automatically. In axios 1.x, use `withXSRFToken: true`.
- If a request returns 419, refresh the CSRF cookie and retry once.
- Do not disable CSRF for stateful browser routes.
- CSRF exceptions are allowed only for stateless machine-to-machine endpoints if added later and protected by signed tokens.

### 10.6 Cookies and sessions

Local:

```text
SESSION_DRIVER=database
SESSION_DOMAIN=localhost
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
SESSION_ENCRYPT=true
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:5174
```

Production:

```text
SESSION_DOMAIN=.sonalifoshol.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
SESSION_ENCRYPT=true
SANCTUM_STATEFUL_DOMAINS=admin.sonalifoshol.com,www.sonalifoshol.com,sonalifoshol.com
```

Rules:

- Session cookie is HttpOnly and not readable by JavaScript.
- Session ID changes on login.
- Logout invalidates the session and regenerates the CSRF token.
- Expired sessions are cleaned up by Laravel's lottery.
- Database sessions make server-side revocation possible.

### 10.7 Password reset

Admin:

- `POST /api/v1/auth/admin/forgot-password` with email.
- Always return a generic success message.
- Queue a reset email when the account exists.
- `POST /api/v1/auth/admin/reset-password` with email, token, password, and confirmation.
- Admin reset tokens expire after 60 minutes.
- Superadmin can also rotate a password locally with an Artisan command.

Customer:

- Same flow with the `customers` broker.
- Reset links point to the storefront URL.

### 10.8 Superadmin rotation

Artisan command:

```powershell
php artisan admin:password admin@sonalifoshol.com
```

Behavior:

- Prompt for a new password without echoing it.
- Validate the password policy.
- Hash with Argon2id.
- Revoke the user's sessions.
- Audit the change.
- Refuse to run with a command-line password argument to avoid shell history leaks.

Optional command for local bootstrap:

```powershell
php artisan admin:ensure-superadmin
```

Creates the env-defined superadmin only if missing.

### 10.9 Rate limits

| Action | Limit |
|---|---|
| Admin login | 5 per minute per login+IP |
| Customer login | 5 per minute per email+IP |
| 2FA challenge | 5 per minute per challenge/IP |
| Forgot password | 3 per minute per email+IP |
| Order lookup | 5 per minute per IP |
| Order creation | 10 per minute per IP |
| Public reads | 120 per minute per IP |
| Admin authenticated requests | 300 per minute per user |
| Uploads | 20 per minute per admin |

Return `429` with a `Retry-After` header and a generic message.

---

## 11. Authorization

- `superadmin`: all admin resources, manage admin users, rotate settings, destructive operations.
- `admin`: products, categories, orders, customers, reviews, calendar, uploads. Read settings; update non-security storefront content only if allowed by policy. No admin-user management.
- Policies: `ProductPolicy`, `OrderPolicy`, `CustomerPolicy`, `ReviewPolicy`, `SettingsPolicy`, `UserPolicy`.
- Every admin route uses `auth:admin` plus the relevant policy.
- Admin guard users must have `is_active = true`.
- Inactive users are rejected at login and on each request.

---

## 12. API specification

### 12.1 Conventions

- JSON requests and responses, UTF-8.
- API version in the path: `/api/v1`.
- Money values are integers in BDT.
- Dates are ISO 8601 UTC strings.
- Pagination parameters: `page` and `limit`.
- Pagination response:

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 29,
    "totalPages": 2
  }
}
```

- Accept `limit` for compatibility with the admin frontend and map it to `per_page` internally. Cap `limit` at 100.
- Validation errors: HTTP 422.

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "phone": ["The phone field must match the Bangladeshi mobile format."]
  }
}
```

- Auth errors: 401. Authorization errors: 403. Not found: 404. Invalid transition: 409. Rate limit: 429.

### 12.2 Health and CSRF

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | Public | Set CSRF cookie |
| GET | `/api/v1/health` | Public | Health check |

### 12.3 Admin auth endpoints

| Method | Path | Auth | Body/params | Response |
|---|---|---|---|---|
| POST | `/api/v1/auth/admin/login` | Public | `login`, `password`, `remember?` | User or 2FA challenge |
| POST | `/api/v1/auth/admin/2fa/challenge` | Public challenge | `challenge_token`, `code` | User |
| POST | `/api/v1/auth/admin/logout` | Admin | None | `{success:true}` |
| GET | `/api/v1/auth/admin/me` | Admin | None | Admin user |
| PUT | `/api/v1/auth/admin/password` | Admin | `current_password`, `password`, `password_confirmation` | `{success:true}` |
| POST | `/api/v1/auth/admin/2fa/enable` | Admin | None | Secret, QR SVG, recovery codes |
| POST | `/api/v1/auth/admin/2fa/confirm` | Admin | `code` | `{success:true}` |
| POST | `/api/v1/auth/admin/2fa/disable` | Admin | `password`, `code` | `{success:true}` |
| POST | `/api/v1/auth/admin/2fa/recovery-codes` | Admin | `password` | New codes |
| POST | `/api/v1/auth/admin/forgot-password` | Public | `email` | Generic success |
| POST | `/api/v1/auth/admin/reset-password` | Public | `email`, `token`, `password`, `password_confirmation` | `{success:true}` |

### 12.4 Customer auth endpoints

| Method | Path | Auth | Body | Response |
|---|---|---|---|---|
| POST | `/api/v1/auth/customer/register` | Public | name, phone, email, password, password_confirmation | Customer |
| POST | `/api/v1/auth/customer/login` | Public | email, password | Customer |
| POST | `/api/v1/auth/customer/logout` | Customer | None | `{success:true}` |
| GET | `/api/v1/auth/customer/me` | Customer | None | Customer |
| PUT | `/api/v1/auth/customer/password` | Customer | current_password, password, password_confirmation | `{success:true}` |
| POST | `/api/v1/auth/customer/forgot-password` | Public | email | Generic success |
| POST | `/api/v1/auth/customer/reset-password` | Public | email, token, password, password_confirmation | `{success:true}` |

### 12.5 Admin dashboard

| Method | Path | Params | Response |
|---|---|---|---|
| GET | `/api/v1/admin/dashboard/summary` | None | `todayOrders`, `todayRevenue`, `pendingOrders`, `lowStockCount`, `revenue`, `orderCount`, `averageOrderValue`, `revenueChangePct`, `orderChangePct` |
| GET | `/api/v1/admin/dashboard/sales` | `range=7d` or `30d` or `90d` | `[{date, revenue, orders}]` |
| GET | `/api/v1/admin/dashboard/status-breakdown` | None | `[{status, count, total}]` |
| GET | `/api/v1/admin/dashboard/top-products` | `limit=5` | `[{productId, nameBn, slug, image, unitsSold, revenue}]` |

Definitions:

- Today is calculated in Asia/Dhaka and compared to stored UTC timestamps.
- Revenue excludes `rejected` and `cancelled`.
- Low stock counts active variants with `stock <= low_stock_threshold`.
- Change percentages compare the current range to the previous equivalent range.

### 12.6 Admin products

| Method | Path | Params/body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/products` | `category`, `search`, `stock`, `status`, `sort`, `page`, `limit` | Paginated admin products |
| GET | `/api/v1/admin/products/{id}` | None | Admin product |
| POST | `/api/v1/admin/products` | Product payload | Created product |
| PUT | `/api/v1/admin/products/{id}` | Product payload | Updated product |
| DELETE | `/api/v1/admin/products/{id}` | None | `{success:true}`; soft delete |
| PATCH | `/api/v1/admin/products/bulk` | `action=publish` or `unpublish` or `delete`, `ids[]` | `{count}` |
| POST | `/api/v1/admin/uploads` | multipart `file` | `{url}` |

Admin filters:

- `stock`: `all`, `in`, `low`, `out`.
- `status`: `all`, `active`, `draft`.
- `sort`: `newest`, `name`, `price`, `stock`.

Product payload:

```json
{
  "slug": "example-product",
  "nameBn": "উদাহরণ",
  "nameEn": "Example",
  "category": "rice",
  "originBn": "দিনাজপুর",
  "image": "http://localhost:8000/storage/products/example.svg",
  "images": ["http://localhost:8000/storage/products/example-2.svg"],
  "badgeBn": "নতুন",
  "inStock": true,
  "status": "active",
  "shortDescBn": "সংক্ষিপ্ত বর্ণনা",
  "fullDescBn": "বিস্তারিত বর্ণনা",
  "nutritionHighlights": ["ফাইবার সমৃদ্ধ"],
  "nutritionFacts": [
    { "labelBn": "প্রতি ১০০ গ্রাম", "valueBn": "৩৫০ কিলোক্যালরি" }
  ],
  "cookingTipsBn": "রান্নার নিয়ম",
  "weights": [
    {
      "label": "১ কেজি",
      "grams": 1000,
      "price": 105,
      "mrp": 120,
      "stock": 50,
      "sku": "example-1000"
    }
  ]
}
```

Validation:

- `slug`: required, lowercase, unique, max 160.
- `category`: exists by slug.
- `nameBn`: required.
- `status`: active/draft.
- `weights`: at least one; grams > 0; price > 0; mrp >= price; stock >= 0; unique grams per product.
- `nutritionFacts`: array of objects with non-empty `labelBn` and `valueBn`.
- `image` and `images`: uploaded storage URLs or trusted seeded URLs.
- Image upload: jpg/jpeg/png/webp, max 5 MB, valid image MIME, no SVG.

Admin product response includes `nutritionFacts`, `cookingTipsBn`, `stock`, and `sku` in addition to existing fields.

### 12.7 Admin categories

| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/categories` | None | Categories with product counts |
| POST | `/api/v1/admin/categories` | `slug`, `nameEn`, `nameBn`, `order`, `hidden` | Category |
| PUT | `/api/v1/admin/categories/{id}` | Partial category | Category |
| DELETE | `/api/v1/admin/categories/{id}` | None | Success; 409 if products exist |

### 12.8 Admin orders

| Method | Path | Params/body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/orders` | `status`, `search`, `from`, `to`, `zone`, `payment`, `page`, `limit` | Paginated orders |
| GET | `/api/v1/admin/orders/{id}` | None | Order |
| PATCH | `/api/v1/admin/orders/{id}/status` | status, reason?, feedback?, courier?, trackingId? | Updated order |

Transition bodies:

```json
{ "status": "confirmed" }
```

```json
{
  "status": "rejected",
  "reason": "out_of_stock",
  "feedback": "Stock unavailable."
}
```

```json
{
  "status": "shipped",
  "courier": "Pathao",
  "trackingId": "PT123456"
}
```

Rules:

- Allowed transitions in section 13.
- Rejection reason must be one of the configured reasons.
- A cancelled/rejected order cannot transition again.
- Every transition writes history with the admin name.
- Restore stock only when entering rejected/cancelled and `stock_restored_at` is null.
- Return the updated order with items, history, rejection, and courier.
- Admin order response shape matches the existing frontend `Order` type.

### 12.9 Admin customers

| Method | Path | Params/body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/customers` | `search`, `flagged`, `sort`, `page`, `limit` | Paginated customers |
| GET | `/api/v1/admin/customers/{id}` | None | Customer |
| GET | `/api/v1/admin/customers/{id}/orders` | None | Customer orders |
| PATCH | `/api/v1/admin/customers/{id}/flag` | `{flagged:boolean}` | Customer |

Customer response computes:

- `orderCount`: count of orders.
- `totalSpent`: sum of totals excluding rejected/cancelled.
- `lastOrderAt`: maximum order creation date.

### 12.10 Admin reviews

| Method | Path | Params/body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/reviews` | `status`, `search`, `rating`, `page`, `limit` | Paginated reviews |
| PATCH | `/api/v1/admin/reviews/{id}` | `{status}` | Review |
| DELETE | `/api/v1/admin/reviews/{id}` | None | Success |

Review response:

```json
{
  "id": "01J...",
  "productId": "01J...",
  "productNameBn": "মিনিকেট চাল",
  "author": "রফিকুল ইসলাম",
  "location": null,
  "rating": 5,
  "text": "চাল খুব ভালো।",
  "status": "approved",
  "createdAt": "2026-11-13T07:00:00.000Z"
}
```

General reviews have `productId` and `productNameBn` as `null`.

### 12.11 Admin calendar

| Method | Path | Params/body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/calendar/events` | `from`, `to` | Events |
| POST | `/api/v1/admin/calendar/events` | title, start, end?, allDay, note?, orderId? | Event |
| PUT | `/api/v1/admin/calendar/events/{id}` | Partial event | Event |
| DELETE | `/api/v1/admin/calendar/events/{id}` | None | Success |

Created admin events default to `type=admin`. Delivery events are imported or linked to orders.

### 12.12 Admin settings

| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/settings` | None | Admin settings |
| PUT | `/api/v1/admin/settings` | Admin settings payload | Updated settings |

Admin payload uses the existing frontend keys plus new fields:

```json
{
  "storeName": "Sonali Foshol Organics",
  "storeNameBn": "সোনালী ফসল অর্গানিকস",
  "brandTaglineBn": "খাঁটি গ্রামের খাবার",
  "whatsappNumber": "8801712345678",
  "facebookUrl": "https://facebook.com/sonalifoshol",
  "messengerUrl": "https://m.me/sonalifoshol",
  "deliveryFeeInside": 60,
  "deliveryFeeOutside": 120,
  "freeShippingThreshold": 2000,
  "lowStockThreshold": 10,
  "phoneDisplay": "+৮৮০ ১৭১২-৩৪৫৬৭৮",
  "email": "hello@sonalifoshol.com",
  "addressBn": "চিরিরবন্দর, দিনাজপুর, বাংলাদেশ"
}
```

Validation:

- Fees and thresholds: non-negative integers.
- Free shipping threshold: non-negative.
- WhatsApp: digits only after normalization, 10-15 digits.
- URLs: valid https URLs.
- Email: valid email.

### 12.13 Admin users (superadmin only)

| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/api/v1/admin/users` | None | Admin users |
| POST | `/api/v1/admin/users` | name, email, username, password, role | User |
| PATCH | `/api/v1/admin/users/{id}` | name?, role?, isActive? | User |
| POST | `/api/v1/admin/users/{id}/reset-password` | password | Success |

The current admin UI has no user-management page. These endpoints can exist without UI in Phase 1 and be added later.

### 12.14 Storefront public endpoints

| Method | Path | Params | Response |
|---|---|---|---|
| GET | `/api/v1/storefront/settings` | None | Public settings |
| GET | `/api/v1/storefront/categories` | None | Visible categories |
| GET | `/api/v1/storefront/products` | `category`, `search`, `sort=default` or `price-asc` or `price-desc` | All available products |
| GET | `/api/v1/storefront/products/{slug}` | None | Product |
| GET | `/api/v1/storefront/products/{slug}/related` | `limit=4` | Related products |
| GET | `/api/v1/storefront/reviews` | `limit=20` | Approved reviews |
| POST | `/api/v1/storefront/orders` | Order payload | Receipt |
| POST | `/api/v1/storefront/orders/lookup` | Order number + phone | Order summary |

Public product response:

```json
{
  "id": "01J...",
  "slug": "dinajpur-old-kataribhog-rice",
  "nameBn": "দিনাজপুরের পুরনো কাটারিভোগ চাল",
  "nameEn": "Dinajpur Aged Kataribhog Rice",
  "category": "rice",
  "originBn": "চিরিরবন্দর, দিনাজপুর",
  "image": "http://localhost:8000/storage/products/kataribhog-rice.svg",
  "badgeBn": "সেরা বিক্রিত",
  "inStock": true,
  "shortDescBn": "প্রাকৃতিক সুগন্ধযুক্ত চাল।",
  "fullDescBn": "বিস্তারিত বর্ণনা।",
  "nutritionHighlights": ["ফাইবার সমৃদ্ধ"],
  "nutritionFacts": [
    { "labelBn": "প্রতি ১০০ গ্রাম", "valueBn": "৩৫০ কিলোক্যালরি" }
  ],
  "cookingTipsBn": "রান্নার নিয়ম।",
  "weights": [
    { "label": "১ কেজি", "grams": 1000, "price": 105, "mrp": 120 }
  ]
}
```

Public product rules:

- Only `status=active`, not soft-deleted.
- `inStock` is false when the product flag is false or total stock is zero.
- Do not expose exact stock counts publicly.
- Do not expose draft products.
- `category` is the slug.
- `image` and gallery images are absolute URLs.

Public categories:

- Exclude hidden categories.
- Do not include the `all` pseudo-category; the storefront adds it client-side.
- Include `productCount` if useful; storefront ignores extra fields.

Public reviews:

- Only approved.
- Return product-linked and general reviews.
- Storefront shape:

```json
{
  "id": "01J...",
  "customerName": "তানভীর আহমেদ",
  "location": "উত্তর, ঢাকা",
  "rating": 5,
  "commentBn": "অসাধারণ!"
}
```

For product-linked reviews, return `customerName = author`, `commentBn = text`, and `location` if present.

### 12.15 Create order

`POST /api/v1/storefront/orders`

Request:

```json
{
  "clientRequestId": "a4f3d8e0-3f5b-4d1a-9e77-2f5c1b8d9a10",
  "customer": {
    "name": "রফিকুল ইসলাম",
    "phone": "01711234567",
    "email": "customer@example.com",
    "district": "ঢাকা",
    "address": "বাসা ১২, রোড ৫, ধানমন্ডি, ঢাকা",
    "note": "দুপুরের পরে কল করবেন।"
  },
  "items": [
    {
      "productId": "01J...",
      "grams": 1000,
      "qty": 2
    }
  ],
  "deliveryZone": "inside-dhaka",
  "paymentMethod": "cod"
}
```

Backward compatibility: the current storefront sends `slug`, `title`, `weightLabel`, `unitPrice`, `subtotal`, `deliveryFee`, and `total`. The backend may accept those fields but must ignore all client prices and totals. Resolve the product and weight from `productId`/`slug` and `grams`; never trust the client's price.

Validation:

- `customer.name`: 3-120 characters.
- `customer.phone`: 11 digits starting `01`, third digit 3-9.
- `customer.email`: optional valid email.
- `district`: required.
- `address`: 10-1000 characters.
- `note`: max 300.
- `deliveryZone`: `inside-dhaka` or `outside-dhaka`; also accept underscore variants and normalize.
- `paymentMethod`: `cod`, `whatsapp`, `messenger`.
- `items`: 1-50 items; qty 1-99; grams positive.
- `clientRequestId`: optional UUID; unique.

Server calculation:

```text
line_total = database_unit_price * qty
subtotal = sum(line_total)
delivery_fee = 0 if subtotal >= free_shipping_threshold
               else inside_fee or outside_fee
total = subtotal + delivery_fee - discount
```

Response:

```json
{
  "success": true,
  "data": {
    "orderId": "SFO-2026-8492",
    "orderNumber": "SFO-2026-8492",
    "id": "01J...",
    "createdAt": "2026-11-19T10:30:00.000Z",
    "customer": {
      "name": "রফিকুল ইসলাম",
      "phone": "01711234567",
      "email": "customer@example.com",
      "district": "ঢাকা",
      "address": "বাসা ১২, রোড ৫, ধানমন্ডি, ঢাকা",
      "note": "দুপুরের পরে কল করবেন।"
    },
    "items": [
      {
        "productId": "01J...",
        "slug": "dinajpur-old-kataribhog-rice",
        "title": "দিনাজপুরের পুরনো কাটারিভোগ চাল",
        "weightLabel": "১ কেজি",
        "grams": 1000,
        "unitPrice": 105,
        "qty": 2
      }
    ],
    "deliveryZone": "inside-dhaka",
    "paymentMethod": "cod",
    "subtotal": 210,
    "deliveryFee": 60,
    "total": 270
  }
}
```

`orderId` is the display order number for storefront compatibility.

Inventory errors:

```json
{
  "success": false,
  "message": "Some items are unavailable.",
  "errors": {
    "items": [
      "দিনাজপুরের পুরনো কাটারিভোগ চাল (১ কেজি): only 1 left."
    ]
  }
}
```

Idempotency:

- If `client_request_id` already exists, return the existing order receipt with 200.
- The frontend should generate the UUID once per checkout attempt and reuse it on retry.

### 12.16 Order lookup

`POST /api/v1/storefront/orders/lookup`

```json
{
  "orderNumber": "SFO-2026-8492",
  "phone": "01711234567"
}
```

Rules:

- Require both fields.
- Normalize phone digits.
- Return a generic 404 when no match.
- Rate limit by IP and by order number.
- Return only the fields needed by the tracking page: order number, status, created date, expected delivery date, items, totals, customer name, district, and status history summary.
- Do not expose internal IDs, customer email, IP, or admin notes.

### 12.17 Customer order history

| Method | Path | Auth | Response |
|---|---|---|---|
| GET | `/api/v1/storefront/customer/orders` | Customer | Paginated own orders |
| GET | `/api/v1/storefront/customer/orders/{orderNumber}` | Customer | Own order |

A customer can see only orders where `orders.customer_id` matches their ID. Guest orders are not retroactively attached.

### 12.18 Error reference

| HTTP | Meaning | Example |
|---|---|---|
| 400 | Malformed request | Invalid JSON |
| 401 | Not authenticated | Missing/expired session |
| 403 | Authenticated but forbidden | Staff calling superadmin route |
| 404 | Resource not found | Unknown product slug |
| 409 | Conflict/invalid state | Invalid order transition |
| 419 | CSRF token mismatch | Missing CSRF cookie |
| 422 | Validation failed | Invalid phone, insufficient stock |
| 429 | Rate limited | Too many login attempts |
| 500 | Server error | Unexpected exception; log with request ID |

Never leak stack traces in production.

---

## 13. Business rules

### 13.1 Order creation algorithm

```text
begin transaction
  if client_request_id exists:
    return existing order
  load settings
  normalize phone and zone
  validate customer fields
  lock each selected product_weight row in a deterministic order
  for each item:
    if product not active / soft deleted / not in_stock:
      throw unavailable
    if weight does not belong to product:
      throw invalid item
    if stock < qty:
      throw insufficient stock
    calculate line total from weight price
  calculate subtotal
  calculate delivery fee
  calculate total
  upsert customer by phone
  create order
  create order items snapshots
  decrement stock for each weight
  insert status history: pending / "Order placed by customer"
  commit
  dispatch queued emails
  return receipt
```

Use `lockForUpdate()` on weights. Lock rows in sorted ID order to reduce deadlocks. On any failure, roll back the entire transaction.

### 13.2 Stock restoration

On transition to `rejected` or `cancelled`:

```text
begin transaction
  lock order
  if order.stock_restored_at is not null:
    commit and return
  for each order item with product_weight_id:
    increment stock by qty
  set order.stock_restored_at = now
  update order status
  insert history
  commit
```

This is idempotent. Never restore stock twice.

### 13.3 Status transitions

| From | Allowed to |
|---|---|
| pending | confirmed, rejected, cancelled |
| confirmed | processing, cancelled |
| processing | shipped, cancelled |
| shipped | delivered |
| delivered | none |
| rejected | none |
| cancelled | none |

Rejection reasons: `out_of_stock`, `unreachable`, `invalid_address`, `fake_order`, `outside_area`, `other`.

### 13.4 Totals and discount

- Unit price comes from the database at order time.
- Line total is integer multiplication.
- Delivery fee is free when subtotal reaches the settings threshold.
- Discount is zero for storefront orders. Imported demo orders may have a discount.
- Total never comes from the client.
- Store an item price snapshot so later product price changes do not alter history.

### 13.5 Expected delivery

Initial rule:

- Inside Dhaka: order date + `inside_delivery_days` (default 2).
- Outside Dhaka: order date + `outside_delivery_days` (default 4).
- Use Asia/Dhaka business dates, store UTC.
- An admin can override the date later if a UI is added.

### 13.6 Customer upsert

For guest checkout:

- Find customer by normalized phone.
- If found: update name, district, and address to the latest checkout values; do not clear `flagged`; do not overwrite email/password with null; update `last_order_at`.
- If not found: create a customer with no password; store the checkout email as contact email, but do not treat it as a login email until an account is created.
- Attach `customer_id` to the order.

Aggregates:

- `orderCount`: all orders for the customer.
- `totalSpent`: sum of `total` for orders excluding rejected/cancelled.
- `lastOrderAt`: max created_at.

### 13.7 Product visibility and stock

- Draft products never appear publicly.
- Soft-deleted products never appear publicly.
- `in_stock = false` hides the product from public availability.
- Public stock is binary (`inStock`), not a count.
- Admin stock filters:
  - `in`: total stock > low_stock_threshold.
  - `low`: 0 < total stock <= low_stock_threshold.
  - `out`: total stock = 0.
- Low stock count on the dashboard follows the same threshold.

### 13.8 Reviews

- Admin can approve, hide, or delete.
- Public sees approved only.
- General testimonials have no product link.
- Deleting a product sets review `product_id` to null; the snapshot name remains.
- Storefront review submission is not included in Phase 1/2 unless explicitly added later.

### 13.9 Calendar

- Admin events are manually managed.
- Delivery events can be linked to orders.
- Query by `from`/`to` overlaps: `start_at < to` and (`end_at` is null or `end_at >= from`).
- All-day events use midnight boundaries.

### 13.10 Settings cache

- Cache the settings row and invalidate on update.
- Do not use stale settings for order fee calculation; read inside the transaction or refresh before calculating.
- Public and admin resources map the same row differently.

### 13.11 Order numbers

- Format: `SFO-YYYY-NNNN`.
- Use a database-safe generator and retry on duplicate key.
- Never reuse a number.
- Display `order_number` to customers and staff.

---

## 14. Security

### 14.1 Threat model and controls

| Threat | Control |
|---|---|
| Credential stuffing | Rate limits, generic errors, optional 2FA, strong password policy |
| Session theft | HttpOnly/Secure cookies, session encryption, HTTPS, short idle lifetime |
| CSRF | Sanctum stateful middleware, CSRF cookie, `X-XSRF-TOKEN` |
| XSS | React escaping, no HTML rendering by default, strict uploads, security headers |
| SQL injection | Eloquent/query builder bindings; no raw concatenation |
| Mass assignment | `$fillable` allowlists or guarded models; FormRequests |
| IDOR | Policies, customer ownership checks, admin-only routes |
| Order enumeration | Order number + phone lookup, rate limits, generic errors |
| Price tampering | Server-side price and total calculation |
| Overselling | Transactions, row locks, stock checks |
| Duplicate orders | Idempotency key + unique constraint |
| Malicious uploads | MIME validation, extension whitelist, size limit, no SVG uploads |
| Brute force | Rate limits, account lockout/backoff |
| Privilege escalation | Role checks, superadmin-only routes |
| Secret leakage | `.env` only, no secrets in logs, redacted audit |
| CORS abuse | Origin allowlist, credentials only for known fronts |
| Clickjacking | `X-Frame-Options: DENY` for API/static sites |
| MIME sniffing | `X-Content-Type-Options: nosniff` |
| Referrer leakage | `Referrer-Policy: strict-origin-when-cross-origin` |

### 14.2 Validation and mass assignment

- Use FormRequest classes for every write endpoint.
- Define explicit `$fillable` on models.
- Never accept `id`, `created_at`, password hashes, or role from public payloads.
- Admin role changes require superadmin.
- Validate enums with Laravel `Rule::in` or enum rules.
- Validate arrays with nested rules.
- Validate slugs with a safe lowercase alphanumeric-and-hyphen pattern.

### 14.3 Upload security

- Allowed: jpg, jpeg, png, webp.
- Max: 5 MB.
- Verify the actual image with Laravel's `image` rule or `getimagesize`.
- Generate random filenames; never use client filenames.
- Store outside the web root and serve via `storage:link`.
- Disallow SVG uploads because SVG can contain scripts.
- Seeded demo SVGs are trusted and served with `Content-Type: image/svg+xml`.
- Optional: strip EXIF metadata.
- Optional: generate thumbnails with GD.

### 14.4 CORS and CSRF

- Allow only configured origins.
- `supports_credentials = true`.
- Do not use wildcard origins with credentials.
- Keep CSRF enabled for both SPAs.
- Handle 419 with a single CSRF refresh and retry.
- Do not expose CSRF tokens in URLs.

### 14.5 Security headers

Add middleware or web-server headers:

```text
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

For static SPAs, add headers in Nginx or the hosting provider.

### 14.6 Logging and audit

- Log authentication success/failure, 2FA changes, password changes, role changes, product/order/settings changes, deletions, and upload failures.
- Redact passwords, tokens, cookies, secrets, and full request bodies containing credentials.
- Include request ID, IP, and user agent.
- Keep audit logs in the database, not only in files.
- Set log retention and rotation.

### 14.7 Production hardening

- `APP_ENV=production`, `APP_DEBUG=false`.
- HTTPS only.
- Secure cookies.
- Database not exposed to the internet.
- Dedicated DB user with only required grants.
- SSH keys, no password SSH.
- Firewall: 22, 80, 443 only.
- Fail2ban or equivalent.
- Regular OS/PHP/Laravel/package updates.
- Disable directory listing.
- Set `expose_php=Off`.
- Remove unused services.
- Keep `.env` permissions restricted.
- Back up and test restores.

---

## 15. Email and queues

### 15.1 Jobs

| Job | Trigger | Recipient |
|---|---|---|
| `SendNewOrderAdminNotification` | Order created | Settings `email` |
| `SendOrderConfirmation` | Order created, customer email exists | Customer |
| `SendOrderStatusUpdate` | Status changed, customer email exists | Customer |
| `SendAdminPasswordReset` | Forgot password | Admin |
| `SendCustomerPasswordReset` | Forgot password | Customer |

### 15.2 Templates

- New order admin notification: order number, customer, phone, items, totals, admin link.
- Customer confirmation: order number, items, totals, delivery estimate, support contact.
- Status update: order number, new status, message.
- Password reset: reset link and expiry.

Use Markdown mail templates. Keep Bangla content where the storefront is Bangla-first.

### 15.3 Local email

Default:

```dotenv
MAIL_MAILER=log
```

Mail is written to `storage/logs/laravel.log`.

Optional Mailpit:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
```

Open `http://localhost:8025`.

### 15.4 Queue worker

Local:

```powershell
php artisan queue:work --tries=3 --timeout=90
```

Production: Supervisor or systemd. Restart workers on deploy.

Failed jobs:

```powershell
php artisan queue:failed
php artisan queue:retry all
```

### 15.5 Email logs

- Create an `email_logs` row before sending.
- Mark `sent` or `failed`.
- Never store full message bodies if they contain sensitive tokens.
- Do not log reset tokens.

---

## 16. Admin frontend integration

### 16.1 Environment

`.env.local`:

```dotenv
VITE_USE_MOCK_DATA=false
VITE_API_BASE_URL=http://localhost:8000
VITE_STOREFRONT_URL=http://localhost:5174
```

Keep mock mode available:

```dotenv
VITE_USE_MOCK_DATA=true
```

### 16.2 Files to change

| File | Change |
|---|---|
| `src/config/env.ts` | Default API base URL to `http://localhost:8000`; keep mock switch |
| `src/api/axiosInstance.ts` | Add `withCredentials`, `withXSRFToken`; remove Bearer token; add 419 retry and 401 handling |
| `src/api/http.ts` | Preserve envelope handling; add CSRF refresh helper |
| `src/api/auth.api.ts` | New `/api/v1/auth/admin/*` paths; return user, not token |
| `src/store/auth.store.ts` | Remove token; bootstrap `/me`; clear legacy token |
| `src/lib/token.ts` | Remove or stop using |
| `src/api/product.api.ts` | `/api/v1/admin/*` paths; support new product fields |
| `src/api/category.api.ts` | `/api/v1/admin/*` paths |
| `src/api/order.api.ts` | `/api/v1/admin/*` paths |
| `src/api/customer.api.ts` | `/api/v1/admin/*` paths; use customer orders endpoint |
| `src/api/review.api.ts` | `/api/v1/admin/*` paths; nullable product |
| `src/api/calendar.api.ts` | `/api/v1/admin/*` paths |
| `src/api/settings.api.ts` | `/api/v1/admin/*` paths; extra fields |
| `src/api/dashboard.api.ts` | `/api/v1/admin/*` paths |
| `src/types/index.ts` | Role `superadmin/admin`; nullable review product; nutrition facts; cooking tips; settings extras |
| `src/components/products/NutritionTags.tsx` or a new repeater | Add nutrition facts UI |
| Product form page | Add cooking tips and nutrition facts |
| Settings page | Add brand tagline, phone display, email, address |
| Login page | Handle 2FA challenge |
| New security page | Enable/confirm/disable 2FA, regenerate recovery codes |
| `src/pages/ReviewsPage.tsx` | Handle general reviews gracefully |

### 16.3 Axios configuration

```ts
export const axiosInstance = axios.create({
  baseURL: env.apiBaseUrl,
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})
```

CSRF bootstrap:

```ts
export async function ensureCsrfCookie() {
  await axios.get(`${env.apiBaseUrl}/sanctum/csrf-cookie`, {
    withCredentials: true,
  })
}
```

Rules:

- Call `ensureCsrfCookie` before login, logout, and any non-GET request if no CSRF cookie exists.
- On 419, refresh and retry once.
- On 401, clear in-memory auth and redirect to login.
- Do not clear auth on 403.
- Do not persist the session or any token.

### 16.4 Auth store

State:

```ts
interface AuthState {
  user: AdminUser | null
  isAuthenticated: boolean
  isBootstrapping: boolean
  setUser: (user: AdminUser) => void
  clear: () => void
}
```

- On app load, call `/api/v1/auth/admin/me`.
- If 401, the user is logged out.
- Do not persist `user` in localStorage unless it is non-sensitive display data and `/me` remains authoritative.
- Remove the legacy `sfo-admin-token` key on startup.

### 16.5 Login and 2FA UI

- Login form accepts email or username.
- If the response contains `two_factor_required`, show a code input with the challenge token.
- Support recovery codes.
- After success, load `/me`, navigate to the dashboard.
- Show generic login errors.
- Disable the submit button while pending.

### 16.6 Type changes

```ts
export type AdminRole = 'superadmin' | 'admin'

export interface AdminUser {
  id: string
  name: string
  email: string
  username: string | null
  role: AdminRole
  avatar?: string | null
}

export interface WeightVariant {
  label: string
  grams: number
  price: number
  mrp: number
  stock: number
  sku?: string
}

export interface AdminProduct {
  id: string
  slug: string
  nameBn: string
  nameEn?: string
  category: string
  originBn: string
  image: string
  images: string[]
  badgeBn?: string
  inStock: boolean
  status: 'active' | 'draft'
  shortDescBn: string
  fullDescBn: string
  nutritionHighlights: string[]
  nutritionFacts: Array<{ labelBn: string; valueBn: string }>
  cookingTipsBn: string
  weights: WeightVariant[]
  createdAt: string
  updatedAt: string
}

export interface Review {
  id: string
  productId: string | null
  productNameBn: string | null
  author: string
  location?: string | null
  rating: number
  text: string
  status: 'pending' | 'approved' | 'hidden'
  createdAt: string
}

export interface StoreSettings {
  storeName: string
  storeNameBn: string
  brandTaglineBn: string
  whatsappNumber: string
  facebookUrl: string
  messengerUrl: string
  deliveryFeeInside: number
  deliveryFeeOutside: number
  freeShippingThreshold: number
  lowStockThreshold: number
  phoneDisplay: string
  email: string
  addressBn: string
}
```

### 16.7 Admin verification

- `npm run typecheck`
- `npm run build`
- Browser smoke test with API mode:
  - Login.
  - Refresh and confirm `/me` restores the session.
  - Products list, create, edit, upload.
  - Orders list, detail, confirm/ship/reject.
  - Customers list/detail/flag.
  - Reviews approve/hide/delete.
  - Calendar create/update/delete.
  - Settings update.
  - 2FA enable/verify/recovery.
  - Logout and confirm protected routes redirect.

Known issue: `npm run lint` may fail because Windows Application Control blocks oxlint's native binding. Record this as an environment blocker; do not treat it as a code failure.

---

## 17. Storefront integration (Phase 2)

### 17.1 Environment

`.env`:

```dotenv
VITE_USE_MOCK_DATA=false
VITE_API_BASE_URL=http://localhost:8000
```

Keep `VITE_USE_MOCK_DATA=true` for offline/demo work.

### 17.2 Files to change

| File | Change |
|---|---|
| `src/api/axiosInstance.ts` | Add `withCredentials`, `withXSRFToken`; add CSRF helper |
| `src/api/product.api.ts` | `/api/v1/storefront/products` |
| `src/api/category.api.ts` | `/api/v1/storefront/categories` |
| `src/api/review.api.ts` | `/api/v1/storefront/reviews` |
| `src/api/settings.api.ts` | `/api/v1/storefront/settings` |
| `src/api/order.api.ts` | `/api/v1/storefront/orders`; server receipt |
| `src/types/order.types.ts` | Payload can omit client totals; receipt includes authoritative totals |
| `src/types/api.types.ts` | Add order tracking types if needed |
| `src/pages/CheckoutPage.tsx` | Use server receipt; optional email |
| `src/components/checkout/CheckoutForm.tsx` | Add optional email field |
| New page | Order tracking by order number + phone |
| New pages | Optional login, register, account orders |
| Navbar | Add account/track order links |
| App routes | Add new routes |

### 17.3 API mapping

| Storefront function | New path |
|---|---|
| `getAllProducts` | `GET /api/v1/storefront/products` |
| `getProductBySlug` | `GET /api/v1/storefront/products/{slug}` |
| `getRelatedProducts` | `GET /api/v1/storefront/products/{slug}/related?limit=4` |
| `getCategories` | `GET /api/v1/storefront/categories` |
| `getReviews` | `GET /api/v1/storefront/reviews` |
| `getStoreSettings` | `GET /api/v1/storefront/settings` |
| `submitOrder` | `POST /api/v1/storefront/orders` |

### 17.4 Delivery zone mapping

- Keep the storefront type hyphenated: `inside-dhaka`, `outside-dhaka`.
- The API accepts both hyphenated and underscored values.
- Public order receipt returns hyphenated values.
- Admin API returns underscored values.

### 17.5 Checkout changes

- Do not send or trust `subtotal`, `deliveryFee`, or `total`.
- Send `clientRequestId` (`crypto.randomUUID()`), customer, items with product ID/slug and grams, zone, payment, and optional email.
- Display cart totals from `useCartTotals` before ordering; they come from server settings but are display-only.
- After order creation, use the returned receipt values.
- Keep the receipt in `sessionStorage` for the success page.
- If the request fails, allow retry with the same `clientRequestId`.
- Disable the submit button while pending to avoid duplicate clicks.

### 17.6 Order tracking

Add `/track-order`:

- Input: order number and phone.
- Call `POST /api/v1/storefront/orders/lookup`.
- Show status, items, totals, expected date, and status history.
- Do not reveal whether the order number exists without a matching phone.

### 17.7 Optional customer accounts

Add `/login`, `/register`, `/account/orders`, and `/account/orders/:orderNumber`, plus footer/navbar links.

Account behavior:

- Register with name, phone, email, password.
- Login with email/password.
- Account order history shows only linked orders.
- Guest orders remain in lookup, not account history.
- Logout calls the customer logout endpoint.
- Do not store tokens.

If account UI is not desired, the backend customer endpoints can remain unused.

### 17.8 Checkout email

Because email notifications are required, add an optional email field to checkout.

- If provided, the backend stores `customer_email` and queues confirmation/status emails.
- If omitted, only the admin notification is sent.
- Do not make email mandatory for guest checkout unless the business requires it.

### 17.9 Storefront verification

- `pnpm typecheck`
- `pnpm build`
- Smoke test with API mode:
  - Home products load.
  - Category filter and search work.
  - Product detail loads.
  - Cart totals match settings.
  - Checkout creates an order.
  - Receipt totals are server-calculated.
  - The order appears in admin.
  - Order tracking works.
  - Stock decrements and restores after rejection.
  - Optional account flow works if implemented.

---

## 18. Local development workflow

### 18.1 Start services

Terminal 1, MySQL:

```powershell
Start-Service MySQL80
```

Terminal 2, API:

```powershell
Set-Location "D:/TheUser/Programming/trial/sonali-foshol-api"
php artisan serve --host=localhost --port=8000
```

Terminal 3, queue:

```powershell
Set-Location "D:/TheUser/Programming/trial/sonali-foshol-api"
php artisan queue:work --tries=3 --timeout=90
```

Terminal 4, admin:

```powershell
Set-Location "D:/TheUser/Programming/trial/sonali-storefront-admin"
npm run dev
```

Terminal 5, storefront:

```powershell
Set-Location "D:/TheUser/Programming/trial/sonali-foshol-storefront"
pnpm dev --port 5174
```

### 18.2 First run

```powershell
Set-Location "D:/TheUser/Programming/trial/sonali-foshol-api"
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve --host=localhost --port=8000
```

### 18.3 Switch mock/API mode

Admin:

```dotenv
VITE_USE_MOCK_DATA=false
VITE_API_BASE_URL=http://localhost:8000
```

Storefront:

```dotenv
VITE_USE_MOCK_DATA=false
VITE_API_BASE_URL=http://localhost:8000
```

Restart Vite after changing env values.

### 18.4 Reset demo data

```powershell
php artisan migrate:fresh --seed
```

This destroys all local data. Never run it in production.

### 18.5 Caches

```powershell
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### 18.6 Logs

```powershell
Get-Content storage/logs/laravel.log -Tail 100 -Wait
```

### 18.7 Tests

```powershell
php artisan test
```

### 18.8 Demo credentials

Local only:

```text
URL:      http://localhost:5173
Email:    admin@sonalifoshol.com
Password: value of SUPERADMIN_PASSWORD
```

Change before sharing.

---

## 19. Testing strategy

### 19.1 Backend feature tests

Auth:

- Admin login success.
- Wrong password.
- Unknown user.
- Inactive user.
- Rate limit.
- Session regeneration.
- `/me` after login.
- Logout.
- CSRF rejection.
- 2FA required, valid code, invalid code, recovery code, reuse rejection.
- 2FA enable/confirm/disable.
- Password change revokes sessions.
- Forgot/reset password.
- Superadmin seeder idempotency.

Authorization:

- Unauthenticated admin route returns 401.
- Staff cannot access superadmin user management.
- Customer cannot access admin routes.
- Customer cannot read another customer's order.

Products:

- List/filter/sort/pagination.
- Create/update/delete.
- Slug uniqueness.
- Weight validation.
- Image upload valid/invalid type/size.
- Soft delete hides from public.
- Draft/hidden category behavior.

Orders:

- Create with server-calculated totals.
- Client price/total tampering ignored.
- Free shipping threshold.
- Inside/outside delivery fee.
- Insufficient stock rollback.
- Concurrent stock test.
- Idempotency key returns same order.
- Order number uniqueness.
- Status transitions.
- Invalid transition 409.
- Stock restore on reject.
- Stock restore on cancel.
- Restore exactly once.
- Guest lookup by number + phone.
- Wrong phone returns 404.
- Customer order ownership.
- Rejection reason validation.
- Courier fields.
- Status history.

Customers:

- Aggregates.
- Flag update.
- Search.
- Upsert by phone.
- Do not clear flagged/email on checkout.

Reviews:

- Admin approve/hide/delete.
- Public approved only.
- General reviews.
- Product deletion preserves snapshot.

Settings:

- Read/update.
- Validation.
- Public/admin mapping.
- Cache invalidation.

Calendar:

- CRUD.
- Range query.
- Order link.

Seed:

- Expected counts.
- Idempotent re-run.
- All 29 products.
- 18 reviews with 8 general.
- 4 categories.
- Images exist.

### 19.2 Security tests

- No password/token in API responses.
- No secrets in logs.
- CORS rejects unknown origins.
- CSRF required.
- Rate limits return 429.
- Upload rejects SVG and mismatched MIME.
- Order lookup enumeration resistance.
- Mass assignment rejection.
- SQL injection strings handled as data.
- XSS payload stored/returned safely.
- Security headers present in production.
- Cookies HttpOnly/Secure in production.
- Admin route authorization.
- No order created with negative/zero quantity.
- No stock restored twice.

### 19.3 Frontend tests

Admin:

- Typecheck.
- Build.
- Login/logout/refresh.
- All pages render.
- Product create/edit/upload.
- Order transitions.
- Settings update.
- 2FA flow.
- No token in localStorage.
- 401/419/429 handling.

Storefront:

- Typecheck.
- Build.
- Home/catalog/detail.
- Cart totals.
- Checkout.
- Receipt.
- Tracking.
- Account (if implemented).
- API errors.

### 19.4 Manual QA checklist

- [ ] Admin logs in with the seeded superadmin.
- [ ] Admin refreshes and remains logged in.
- [ ] Admin logs out and protected routes redirect.
- [ ] Product with Bangla text displays correctly.
- [ ] Product image upload works and serves from `/storage`.
- [ ] Product appears on storefront.
- [ ] Draft product does not appear.
- [ ] Storefront order is visible in admin.
- [ ] Order total matches server calculation.
- [ ] Tampered total is ignored.
- [ ] Stock decrements.
- [ ] Rejection restores stock.
- [ ] Second rejection does not restore again.
- [ ] Guest lookup works with correct phone.
- [ ] Guest lookup fails with wrong phone.
- [ ] Review approval changes public visibility.
- [ ] Settings fee change affects new orders.
- [ ] Free shipping threshold works.
- [ ] Email job is queued/sent.
- [ ] 2FA enable and login challenge work.
- [ ] No console errors.
- [ ] No secrets in the repository.

---

## 20. Production deployment

### 20.1 Server

- Ubuntu 24.04 LTS or equivalent.
- 2+ GB RAM, 2+ vCPU, 40+ GB disk for a small store.
- Nginx.
- PHP 8.3/8.4 FPM.
- MySQL 8.
- Composer.
- Node only for build pipelines, not required at runtime on the API server.
- TLS certificates via Let's Encrypt.

### 20.2 DNS

| Host | Target |
|---|---|
| `api.sonalifoshol.com` | API server |
| `admin.sonalifoshol.com` | Admin static build |
| `www.sonalifoshol.com` | Storefront static build |
| `sonalifoshol.com` | Redirect to `www` or storefront |

### 20.3 Nginx for API

```nginx
server {
    listen 443 ssl http2;
    server_name api.sonalifoshol.com;

    root /var/www/sonali-foshol-api/public;
    index index.php;

    client_max_body_size 6M;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 20.4 Nginx for SPAs

```nginx
server {
    listen 443 ssl http2;
    server_name admin.sonalifoshol.com;
    root /var/www/sonali-foshol-admin/dist;
    index index.html;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

Repeat for the storefront.

### 20.5 Deploy steps

```bash
cd /var/www/sonali-foshol-api
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart sonali-foshol-worker:*
```

Permissions:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rw storage bootstrap/cache
```

### 20.6 Queue worker

`/etc/supervisor/conf.d/sonali-foshol-worker.conf`:

```ini
[program:sonali-foshol-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sonali-foshol-api/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/sonali-foshol-worker.log
stopwaitsecs=3600
```

### 20.7 Scheduler

Cron:

```cron
* * * * * cd /var/www/sonali-foshol-api && php artisan schedule:run >> /dev/null 2>&1
```

### 20.8 Production checklist

- [ ] `.env` set with production values.
- [ ] `APP_DEBUG=false`.
- [ ] HTTPS and HSTS.
- [ ] Secure cookies.
- [ ] Cookie domain `.sonalifoshol.com`.
- [ ] CORS origins exactly the production fronts.
- [ ] Sanctum stateful domains exactly the production fronts.
- [ ] Database user least privilege.
- [ ] Queue worker running.
- [ ] Scheduler running.
- [ ] `storage:link` done.
- [ ] Writable storage/cache.
- [ ] Backups configured.
- [ ] Log rotation.
- [ ] Fail2ban/firewall.
- [ ] Superadmin password rotated from demo.
- [ ] 2FA enabled for superadmin.
- [ ] Health check monitored.
- [ ] Restore test completed.

---

## 21. Backup and disaster recovery

### 21.1 Database backup

```bash
mysqldump --single-transaction --quick --routines --triggers -u sonali -p sonali_foshol | gzip > /backups/db/sonali_foshol_$(date +%F_%H%M).sql.gz
```

### 21.2 Storage backup

```bash
tar -czf /backups/storage/products_$(date +%F).tar.gz /var/www/sonali-foshol-api/storage/app/public/products
```

### 21.3 Retention

- Daily backups for 14 days.
- Weekly backups for 8 weeks.
- Monthly backups for 12 months.
- Offsite copy.
- Encrypt backups.
- Test restore quarterly.

### 21.4 Restore

1. Put the API in maintenance mode.
2. Restore the database.
3. Restore storage.
4. Run `php artisan migrate --force` if needed.
5. Clear caches.
6. Verify product images and orders.
7. Resume traffic.

---

## 22. Troubleshooting

### 22.1 419 CSRF token mismatch

Causes:

- CSRF cookie not requested.
- `withCredentials` missing.
- `SESSION_DOMAIN` wrong.
- Frontend and API are on different sites with `SameSite=Lax`.
- Config cache contains old values.

Fix:

```powershell
php artisan config:clear
```

Then call `/sanctum/csrf-cookie` and retry.

### 22.2 401 Unauthorized

Causes:

- No session cookie.
- Wrong guard.
- `SANCTUM_STATEFUL_DOMAINS` missing the frontend origin.
- Frontend using `127.0.0.1` while API uses `localhost`.
- Session expired.

Fix:

- Use `localhost` consistently.
- Confirm stateful domains.
- Check DevTools Application cookies.
- Call `/me`.
- Log in again.

### 22.3 CORS errors

- Confirm `CORS_ALLOWED_ORIGINS`.
- Confirm `supports_credentials=true`.
- Do not use wildcard origins.
- Clear config cache.

### 22.4 Cookies not set

- `SESSION_DOMAIN=localhost` in local.
- `SESSION_SECURE_COOKIE=false` in local.
- Use `localhost`, not `127.0.0.1`.
- Do not use `.test` for the API when SPAs run on `localhost`.
- Check browser third-party cookie blocking.

### 22.5 MySQL connection errors

```powershell
Get-Service MySQL80
mysql -u sonali -p -h 127.0.0.1 -P 3306 sonali_foshol
```

Check `.env` credentials, port, and grants.

### 22.6 MySQL access denied

- Verify the host is `localhost` or `127.0.0.1` as granted.
- Check for trailing spaces in `.env`.
- Recreate the user if the password or host grant is wrong.
- Modern PHP 8.3 works with MySQL 8 default authentication; avoid downgrading auth plugins unnecessarily.

### 22.7 Images 404

```powershell
php artisan storage:link
```

Check `APP_URL` and the returned URL. Confirm the file exists under `storage/app/public/products`.

### 22.8 Bangla text garbled

- Database and tables use `utf8mb4`.
- `.env` uses UTF-8.
- API responses are UTF-8 JSON.
- PowerShell display may garble Bangla; verify in the browser and database client.
- Ensure files are saved as UTF-8.

### 22.9 Emails not sending

- Start `php artisan queue:work`.
- Check `MAIL_MAILER`.
- Check `storage/logs/laravel.log` for the log driver.
- Check Mailpit UI for SMTP.
- Check `failed_jobs`.

### 22.10 Port conflicts

Use the port check command in section 6.7. Change `--port` for the API or Vite.

### 22.11 Vite env changes not applied

Restart the dev server. Vite reads `VITE_*` variables at startup.

### 22.12 Admin lint failure

Windows Application Control may block oxlint's native `.node` binding. This is not a code error. Use:

```powershell
npm run typecheck
npm run build
```

Optionally run lint on another machine or a CI environment.

### 22.13 Order totals look wrong

- Confirm settings fees and free-shipping threshold.
- Confirm the database weight price, not the client price.
- Confirm integer math; no floats.
- Confirm the order item snapshots.
- Check whether a demo order has a discount.

### 22.14 Stock becomes negative or is not restored

- Ensure `lockForUpdate()` is used inside a transaction.
- Ensure failed transactions roll back.
- Ensure restoration checks `stock_restored_at`.
- Add a database-level non-negative guard or a check constraint if desired.
- Run the concurrency test.

### 22.15 Session lost after login

- Confirm `withCredentials` on both SPAs.
- Confirm `SESSION_DOMAIN=localhost`.
- Confirm stateful domains include the exact origin (including port).
- Confirm the browser accepts cookies.
- Do not mix `localhost` and `127.0.0.1`.

### 22.16 2FA code rejected

- Check server clock synchronization.
- TOTP windows allow a small clock drift.
- Confirm the correct account secret.
- Recovery codes are one-time.
- If the secret was reset, re-enroll.

---

## 23. Phased implementation checklist

### Phase 0 - Environment and scaffold

- [ ] Install Laravel Herd and PHP.
- [ ] Install MySQL 8 and start `MySQL80`.
- [ ] Create `sonali_foshol` DB and `sonali` user.
- [ ] Create the Laravel project.
- [ ] Install Sanctum and 2FA packages.
- [ ] Configure `.env`, auth guards, Sanctum, CORS.
- [ ] Run `storage:link`.
- [ ] Confirm `php artisan serve` on `localhost:8000`.

### Phase 1 - Schema and seed

- [ ] Create all migrations.
- [ ] Create models, casts, relationships, enums.
- [ ] Create seed asset copies.
- [ ] Implement the demo importer.
- [ ] Import settings, categories, products, weights, customers, orders, reviews, calendar, superadmin.
- [ ] Copy product images.
- [ ] Verify counts: 4 categories, 29 products, 10 customers, 20 orders, 18 reviews, 6 events.
- [ ] Verify seed idempotency.

### Phase 2 - Auth and security

- [ ] Admin login/logout/me.
- [ ] Session regeneration and CSRF.
- [ ] Rate limits.
- [ ] 2FA enable/confirm/disable/challenge/recovery.
- [ ] Customer auth.
- [ ] Password reset.
- [ ] Superadmin rotation command.
- [ ] Audit logs.
- [ ] Policies and roles.

### Phase 3 - Admin API

- [ ] Dashboard endpoints.
- [ ] Products CRUD, bulk, upload.
- [ ] Categories CRUD.
- [ ] Orders list/detail/status transitions.
- [ ] Customers list/detail/orders/flag.
- [ ] Reviews list/update/delete.
- [ ] Calendar CRUD.
- [ ] Settings read/update.
- [ ] Feature tests.

### Phase 4 - Admin frontend

- [ ] Cookie auth and CSRF.
- [ ] Remove bearer/localStorage token.
- [ ] Update endpoint paths.
- [ ] Update types and roles.
- [ ] Add nutrition facts and cooking tips.
- [ ] Extend settings.
- [ ] Add 2FA UI.
- [ ] Typecheck/build/smoke test.

### Phase 5 - Storefront

- [ ] Cookie/CSRF configuration.
- [ ] Update endpoint paths.
- [ ] Server-calculated order totals.
- [ ] Optional checkout email.
- [ ] Order tracking.
- [ ] Optional customer accounts.
- [ ] Typecheck/build/smoke test.

### Phase 6 - Documentation and production readiness

- [ ] Keep this guide current.
- [ ] Add `.env.example`.
- [ ] Add deployment notes.
- [ ] Add backup/restore runbook.
- [ ] Rotate the demo superadmin password.
- [ ] Enable 2FA for the superadmin.
- [ ] Final security review.

---

## 24. Assumptions and open items

- The storefront will show all 29 products after integration.
- The admin UI will be extended for nutrition facts, cooking tips, and storefront settings fields.
- The storefront account UI is optional and belongs to Phase 2.
- Public review submission is not included. Reviews are admin-managed.
- No SMS notifications.
- No online payment gateway. Payment methods remain COD, WhatsApp, and Messenger.
- Guest email is optional; without it, only the admin receives an email.
- Product images from the demo are SVG; uploaded images exclude SVG.
- Admin roles are renamed to `superadmin` and `admin`.
- Settings are a single row.
- No multi-currency and no tax calculation.
- Discount is imported for demo orders and zero for storefront orders.
- Delivery-day settings are fixed defaults until an admin UI is added.
- English and Bangla content are both supported; Bangla is the primary storefront language.
- The Windows lint blocker is an environment issue, not a code issue.
- The backend must never trust client prices, totals, stock, or status transitions.
- ULIDs change demo IDs; all relationships must be imported by natural keys such as slug, phone, and order number.

---

## 25. Appendix

### 25.1 Demo credential policy

- Local only: `admin@sonalifoshol.com` with the value of `SUPERADMIN_PASSWORD`.
- Never commit a real password.
- Rotate the superadmin password before deploying.
- Enable 2FA for the superadmin.
- Keep the recovery codes offline.

### 25.2 Quick API examples

Admin login:

```bash
curl -c cookies.txt -b cookies.txt http://localhost:8000/sanctum/csrf-cookie
curl -c cookies.txt -b cookies.txt -X POST http://localhost:8000/api/v1/auth/admin/login -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"login\":\"admin@sonalifoshol.com\",\"password\":\"sonali123\"}"
curl -c cookies.txt -b cookies.txt http://localhost:8000/api/v1/auth/admin/me -H "Accept: application/json"
```

Public products:

```bash
curl "http://localhost:8000/api/v1/storefront/products?category=rice&sort=price-asc"
```

Create order:

```bash
curl -X POST http://localhost:8000/api/v1/storefront/orders -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"customer\":{\"name\":\"Test Customer\",\"phone\":\"01711234567\",\"district\":\"Dhaka\",\"address\":\"House 1, Road 2, Dhaka\"},\"items\":[{\"productId\":\"REPLACE_WITH_ULID\",\"grams\":1000,\"qty\":1}],\"deliveryZone\":\"inside-dhaka\",\"paymentMethod\":\"cod\"}"
```

### 25.3 Data counts

| Entity | Count |
|---|---|
| Categories | 4 |
| Products | 29 |
| Admin products | 20 |
| Storefront products | 9 |
| Customers | 10 |
| Orders | 20 |
| Reviews | 18 |
| Product-linked reviews | 10 |
| General testimonials | 8 |
| Calendar events | 6 |
| Settings rows | 1 |
| Superadmins | 1 |

### 25.4 Admin-to-public field mapping summary

| Admin | Public/storefront | Database |
|---|---|---|
| `category` slug | `category` slug | `categories.slug` |
| `image` | `image` | `products.image_path` |
| `images[]` | gallery | `product_images.path` |
| `weights[].stock` | hidden | `product_weights.stock` |
| `weights[].sku` | optional/hidden | `product_weights.sku` |
| `nutritionFacts` | `nutritionFacts` | `products.nutrition_facts` |
| `cookingTipsBn` | `cookingTipsBn` | `products.cooking_tips_bn` |
| `deliveryZone` underscore | hyphen | normalized per resource |
| `facebookUrl` | `facebookPageUrl` | `settings.facebook_url` |
| `deliveryFeeInside` | `insideDhakaDelivery` | `settings.delivery_fee_inside` |
| `deliveryFeeOutside` | `outsideDhakaDelivery` | `settings.delivery_fee_outside` |
| `storeNameBn` | `brandNameBn` | `settings.store_name_bn` |

### 25.5 Glossary

| Term | Meaning |
|---|---|
| ULID | Sortable 26-character public identifier |
| SPA | Single-page application |
| Sanctum | Laravel package for SPA cookie auth and API tokens |
| CSRF | Cross-site request forgery protection |
| TOTP | Time-based one-time password for 2FA |
| HSTS | HTTP Strict Transport Security |
| Idempotency key | Client-generated UUID preventing duplicate orders |
| Reserve stock | Decrement stock at order creation |
| Restore stock | Increment stock when an order is rejected/cancelled |
| General review | Testimonial with no product link |

### 25.6 Documentation maintenance

Update this document whenever:

- An endpoint changes.
- A database column is added or removed.
- Auth/session behavior changes.
- Seed counts change.
- Deployment or backup steps change.
- A known blocker is resolved.

The implementation is complete only when the acceptance criteria in sections 19 and 23 pass and the production checklist in section 20.8 is satisfied.

