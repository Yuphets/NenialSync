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

Laravel trusts Vercel's forwarding proxy headers so Vite assets are generated with HTTPS URLs. Keep `APP_URL` set to the final `https://` production domain.

The PHP entry point also normalizes its script path to `/index.php`. This is required because Laravel is mounted at the domain root even though Vercel stores the function under `/api`; without it, Symfony would strip the `/api` prefix from application API routes.

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
SYNC_SHARED_SECRET=<64-character-random-secret>
```

You may replace the individual `DB_*` values with Neon's pooled `DATABASE_URL`. Nenial recognizes both the current `DATABASE_URL` variable and the legacy Vercel `POSTGRES_URL` variable automatically. During deployment, the Composer `vercel` script validates `APP_KEY` and the database configuration, applies pending migrations, and seeds only a completely new installation. Configuration mistakes therefore fail the deployment build with a useful message instead of producing a generic HTTP 500.

The application also derives Neon's `ep-...` endpoint ID from pooled hostnames and supplies it to libpq. This keeps Neon connections compatible with Vercel PHP runtimes whose bundled PostgreSQL client cannot send TLS SNI. `DB_ENDPOINT` is available as an explicit override but is normally unnecessary.

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

## Offline-capable store server

The cloud deployment remains the online storefront and reporting authority. A store-local Laravel/PostgreSQL node serves the counter over the LAN, so POS sales, barcode lookup, login, inventory deduction, and facial-attendance capture continue when the internet connection is down.

### One-time cloud configuration

Generate a long random synchronization secret and add it to the Vercel Production environment as `SYNC_SHARED_SECRET`, then redeploy. Keep this value out of Git and use the same value on the local server.

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### Start the local node

Install Docker Desktop on the store server, give that computer a reserved/static LAN address, and set these values in its private `.env`:

```dotenv
APP_KEY=base64:generate-a-separate-local-key
LOCAL_APP_URL=http://192.168.1.20:8080
LOCAL_DB_PASSWORD=use-a-long-random-password
LOCAL_NODE_ID=store-main
CLOUD_URL=https://nenialsync.vercel.app
SYNC_SHARED_SECRET=the-same-secret-configured-in-vercel
```

Start the local PostgreSQL database, Laravel application, and 30-second synchronization worker:

```powershell
docker compose -f docker-compose.local.yml up -d --build
```

Point counter devices to `http://192.168.1.20:8080` (replace the address with the server's reserved LAN IP). Allow TCP port 8080 through the server firewall only for the trusted store network. Use a UPS and back up the `nenial-postgres` Docker volume regularly.

From an **Administrator PowerShell** on the store server, add the restricted Windows firewall rule once:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\configure-windows-firewall.ps1
```

Create a PostgreSQL backup immediately (the script retains 30 days by default):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\backup-local.ps1
```

Backups are written to the ignored `backups` directory. Periodically copy them to encrypted storage outside this computer. To restore a selected backup during disaster recovery, stop the app and sync services, copy the dump into PostgreSQL, and run `pg_restore`; test this procedure on a non-production database before relying on it.

For an installable PWA on devices other than the server itself, put a trusted HTTPS reverse proxy in front of the LAN address. Browsers permit service workers on HTTPS origins and on `localhost`, but not ordinary HTTP LAN addresses.

### Synchronization behavior

- A local sale and its outbox event commit in the same PostgreSQL transaction.
- The worker pushes original prices, timestamps, cashier identity, and line items with a UUID idempotency key.
- Cloud imports lock inventory rows and can apply each event only once.
- Attendance events use the same durable outbox.
- Cloud inventory is pulled only after every pending local event is accepted.
- If online orders consumed stock while the store was offline, the event is retained as an open conflict. Cloud inventory is not copied over the unresolved local state.
- Admin and Assistant Admin can see pending events, conflicts, and the last successful sync under **Settings** and trigger a manual sync.
- Users, password hashes, roles, employee payroll settings, face subject IDs, and facial-device credentials synchronize over the authenticated TLS sync channel. Local changes use the durable outbox before the cloud snapshot is pulled.

Resolve a conflict by reviewing the physical count and cloud order commitments, making the authorized inventory correction in the cloud workspace, then retrying synchronization. Never delete the local outbox or Docker volume to bypass a conflict.

Manual synchronization and status checks:

```powershell
docker compose -f docker-compose.local.yml exec app php artisan local:sync
docker compose -f docker-compose.local.yml logs -f sync
```

## Facial-recognition terminal setup

Nenial includes a browser-based attendance terminal. Raw images are not stored. Numerical face descriptors stay in IndexedDB on the camera computer; Neon receives only the subject identifier, event ID, timestamp, and confidence.

1. Assign each employee a unique **Face Subject ID** under Workforce.
2. Under **Devices**, register a Facial device and copy its one-time token.
3. On the store camera computer open `http://localhost:8080/face-terminal`. For a separate LAN device, use a trusted HTTPS reverse proxy because browsers do not allow camera access on ordinary HTTP LAN origins.
4. Paste the token, start the camera, select an employee, and capture the three enrollment angles with their consent.
5. During attendance, face matching happens locally and a blink is required before submission. Use consistent lighting and mount the camera around eye level.
6. Use **Remove** under Local enrollments when consent is withdrawn or the employee leaves. Browser templates never reach the server.

The included blink check reduces simple photograph replay, but it is not equivalent to certified depth/IR anti-spoofing. For higher-security sites, use a commercial depth/IR facial terminal with the webhook below.

### Commercial terminal webhook

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
- Facial matching occurs locally in the Nenial browser terminal or on a commercial vendor gateway. Browser templates stay on the terminal and are never synchronized to Neon.
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
