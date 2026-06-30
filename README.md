# Nenial Operations

Production-oriented Laravel 13 + Vue 3 application for a walk-in construction supply store and protected-payment e-commerce storefront.

## Included systems

- Role-based authentication: Admin, Assistant Admin, Cashier, Customer
- Live storefront backed by current sellable inventory
- POS with USB keyboard-wedge and browser-camera barcode scanning
- Transactional inventory with row locks, retry-safe checkout keys, reservations, safety stock, versioning, and an immutable movement ledger
- Protected online orders: stock is reserved at checkout and deducted only after delivered goods are confirmed received
- Employee management, Philippine statutory deductions, incentives, overtime, payroll runs
- Facial-recognition attendance webhook with device tokens and confidence/event tracking
- Daily/custom company reports and CSV export
- Device registration and heartbeat tracking
- User role/access management, audit logs, and password changes
- Responsive Vue SPA with three-second resilient inventory synchronization

The original single-file prototype is preserved in `legacy/prototype.html`.

## Architecture

- **Backend:** Laravel 13 / PHP 8.5
- **Frontend:** Vue 3, Pinia, Vue Router, Vite
- **Production database:** Neon PostgreSQL
- **Deployment:** Vercel using `vercel-php@0.9.0`
- **Realtime behavior:** database-authoritative short polling. This is intentionally compatible with serverless Vercel functions. An external Pusher/Ably channel can be added without changing inventory transactions.

Inventory mutations always execute inside database transactions and acquire `FOR UPDATE` row locks in product-ID order. `stock_quantity` represents physical on-hand units; `reserved_quantity` represents protected online orders; `available_quantity` is computed as `stock - reserved - safety stock`.

POS sales and web orders carry a UUID idempotency key. Safe browser or network retries therefore return the original transaction instead of deducting or reserving inventory twice.

## Local setup

```powershell
Copy-Item .env.example .env
composer install
npm install
php artisan key:generate
```

Configure PostgreSQL in `.env`, then:

```powershell
php artisan migrate --seed
npm run dev
php artisan serve
```

This workstation's PHP distribution ships SQLite extensions disabled. For local SQLite verification use:

```powershell
php -d extension=pdo_sqlite artisan migrate:fresh --seed
php -d extension=pdo_sqlite vendor/bin/phpunit
```

## Neon setup

1. Create a Neon project in the region nearest the Vercel function region.
2. Copy the **pooled** connection details into Vercel environment variables.
3. Set:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=ep-...pooler....neon.tech
DB_PORT=5432
DB_DATABASE=neondb
DB_USERNAME=neondb_owner
DB_PASSWORD=...
DB_SSLMODE=require
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

4. Run migrations once from a trusted workstation or CI job:

```bash
php artisan migrate --force
php artisan db:seed --force
```

Never run destructive migrations automatically during a Vercel request.

## Vercel deployment

The repository includes `vercel.json`, `api/index.php`, serverless `/tmp` storage setup, and `.vercelignore`.

Required Vercel environment variables:

```dotenv
APP_NAME=Nenial
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_TIMEZONE=Asia/Manila
APP_KEY=base64:...
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=neondb
DB_USERNAME=...
DB_PASSWORD=...
DB_SSLMODE=require
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
```

Generate `APP_KEY` locally with `php artisan key:generate --show`. Deploy with the Vercel Git integration or `vercel --prod`. Vercel uses a read-only filesystem; durable uploads must use S3/R2/Vercel Blob rather than Laravel's local disk.

## Initial accounts

Seeder passwords are controlled by `SEED_*_PASSWORD` environment variables. Change every staff password immediately after first deployment.

- `admin@nenial.com`
- `assistant@nenial.com`
- `cashier@nenial.com`
- Demo customer: `demo.user@nenial.test` / `UserDemo2026!`

## Barcode scanner setup

### USB scanner (recommended at the counter)

1. Configure the scanner as **USB HID keyboard**.
2. Enable an **Enter / CR suffix** after every scan.
3. Match the scanner keyboard locale to the POS computer.
4. Print a supported symbology (EAN-13, UPC-A, Code 128) matching `products.barcode`.
5. Open POS Terminal. The barcode field accepts the scan and Enter completes lookup.
6. Use the built-in camera scanner only as a fallback and grant browser camera permission over HTTPS.

Test ten repeated scans before opening the register. A scan must resolve exactly one SKU and must never add beyond `available_quantity`.

## Facial-recognition terminal setup

Nenial deliberately stores only the vendor's **subject identifier**, event ID, timestamp, and confidence—not raw facial templates.

1. Choose a commercial terminal that supports HTTPS webhooks (for example, a supported ZKTeco, Hikvision, or Suprema integration gateway).
2. In **Devices**, register a Facial device and copy its one-time token.
3. Configure the device/integration gateway webhook:

```text
POST https://your-domain.example/api/device/attendance
Authorization: Bearer <device-token>
Content-Type: application/json
```

Payload:

```json
{
  "subject_id": "FACE-1001",
  "event_id": "terminal-01-20260630-0000123",
  "recognized_at": "2026-06-30T07:01:20+08:00",
  "confidence": 98.4,
  "status": "present"
}
```

4. Set the employee's Face Subject ID to the identifier enrolled on the terminal.
5. Require HTTPS, keep tokens in the terminal's secret store, restrict gateway egress IPs where supported, and rotate a token if exposed.
6. Obtain employee consent and define biometric enrollment, retention, deletion, incident-response, and manual attendance policies before production use.

The webhook is idempotent by provider event ID and updates one attendance record per employee/date.

## Production integration boundaries

- `protected_payment` currently implements the order hold, reservation, delivery, receipt, and settlement state machine. Connect a PCI-compliant provider such as your chosen Philippine payment gateway before collecting real card or wallet funds; never store card details in this application.
- Facial matching occurs on the physical terminal or its vendor gateway. Nenial receives authenticated recognition events and does not claim to perform biometric matching in the browser.
- Inventory screens synchronize every three seconds from Neon. PostgreSQL remains authoritative, so simultaneous POS and online purchases are serialized by row locks even if a screen has not refreshed yet.

## Verification

```powershell
php -l app/Services/InventoryService.php
php -d extension=pdo_sqlite vendor/bin/phpunit
npm run build
php artisan route:list --except-vendor
```

The feature suite covers stock deduction, online reservations, receipt settlement, oversell protection, access control, and facial-device attendance.

## Production checklist

- Change seeded passwords and remove/disable the demo customer if not needed.
- Configure Neon pooled connections and database backups.
- Run migrations from CI, not from Vercel runtime requests.
- Configure object storage before accepting uploaded files.
- Register each physical terminal separately and rotate device tokens annually.
- Reconcile inventory movements against physical counts on a scheduled cycle.
- Review Vercel runtime logs and Neon query metrics.
- Validate statutory payroll rates with the responsible accountant before every effective-period change.

Payroll selects the newest `statutory_rates` row whose `effective_from` date has begun. The seeded 2025 SSS and PhilHealth parameters follow the published [SSS contribution guidance](https://www.sss.gov.ph/pay-contribution/) and [PhilHealth 2025 advisory](https://www.philhealth.gov.ph/advisories/2025/PA2025-0002.pdf); an accountant should still approve each new effective row before payroll is finalized.
