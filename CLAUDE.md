# PulseOPS V3 - Developer Documentation

## Overview

PulseOPS is an amusement machine operations management system built for NT Amusements. It manages machines, customers, revenue tracking, commission calculations, job/maintenance tracking, and integrates with the Nayax payment terminal API.

**Live URL:** https://v2.pulseops.com.au
**Customer Portal:** https://v2.pulseops.com.au/portal/login

## Tech Stack

- **Backend:** PHP 8.4, Slim 4 Framework
- **Templates:** Twig 3
- **Database:** MariaDB/MySQL
- **Frontend:** Bootstrap 5, Chart.js, Bootstrap Icons
- **PDF Generation:** dompdf 3
- **Email:** PHPMailer 7
- **Payment Integration:** Nayax Lynx Operational API
- **Containerization:** Docker (Alpine + nginx + PHP-FPM + crond)
- **Process Manager:** Supervisord (nginx, php-fpm, crond)
- **Deployment:** Coolify (Docker-based)

## Project Structure

```
PulseOPS/
├── public/
│   ├── index.php              # Application entry point
│   └── opcache-reset.php      # Emergency opcache reset (temp)
├── src/
│   ├── Controllers/           # Slim route handlers
│   │   ├── AnalyticsController.php
│   │   ├── AuthController.php
│   │   ├── CommissionsController.php
│   │   ├── CustomersController.php
│   │   ├── DashboardController.php
│   │   ├── JobsController.php
│   │   ├── LogViewerController.php
│   │   ├── MachinesController.php
│   │   ├── NayaxController.php
│   │   ├── PortalController.php
│   │   ├── RevenueController.php
│   │   └── SettingsController.php
│   ├── Services/
│   │   ├── AuditService.php       # Audit logging
│   │   ├── AuthService.php        # Admin + portal authentication
│   │   ├── CommissionService.php  # Commission generation logic
│   │   ├── Database.php           # PDO wrapper + query builder
│   │   ├── MailService.php        # SMTP email via PHPMailer
│   │   ├── NayaxService.php       # Nayax Lynx API client
│   │   └── SettingsService.php    # DB-backed settings with caching
│   ├── Helpers/
│   │   └── CommissionCalculator.php  # V3 formula calculator
│   └── Middleware/
│       ├── AuthMiddleware.php        # Admin auth check
│       ├── CsrfMiddleware.php        # CSRF token validation
│       └── PortalAuthMiddleware.php  # Portal auth check
├── templates/
│   ├── layouts/
│   │   ├── admin.twig         # Admin layout (sidebar, nav, sortable JS)
│   │   ├── auth.twig          # Login layout
│   │   └── portal.twig        # Customer portal layout
│   ├── admin/                 # Admin templates (see routes below)
│   ├── portal/                # Customer portal templates
│   ├── pdf/
│   │   └── commission.twig    # PDF commission statement template
│   └── errors/
├── config/
│   ├── app.php                # Not used (Slim uses settings.php)
│   ├── container.php          # PHP-DI container definitions
│   ├── middleware.php          # Slim middleware stack + error handler
│   ├── routes.php             # All route definitions
│   └── settings.php           # Environment-based config
├── cron/
│   ├── bootstrap.php          # Shared cron bootstrap (DI container)
│   ├── nayax-sync.php         # Device sync from Nayax API (every 6h)
│   ├── nayax-transactions.php # Transaction import (every 15min)
│   ├── nayax-revenue.php      # Revenue aggregation (every 30min)
│   ├── generate-commissions.php # Monthly commission generation (1st @ 2am)
│   └── import-worker.php      # Generic import worker
├── database/
│   ├── schema.sql             # Full database schema
│   ├── schema_v2.sql          # Schema with company_id (multi-tenant, unused)
│   ├── seed.sql               # Initial seed data
│   └── migrations/
│       └── add_time_to_job_notes.sql
├── docker/
│   ├── entrypoint.sh          # Container startup (DB wait, schema init, migrations)
│   ├── supervisord.conf       # nginx + php-fpm + crond
│   ├── crontab                # Cron schedule
│   ├── nginx/default.conf
│   └── php/
│       ├── php.ini            # PHP config (opcache, uploads, errors)
│       └── www.conf           # PHP-FPM pool config
├── Dockerfile
├── docker-compose.yml
├── docker-compose.override.yml
├── composer.json
├── composer.lock
├── swagger.json               # Nayax API swagger spec
└── .deploy_version            # Opcache reset trigger file
```

## Architecture

### DI Container (`config/container.php`)

All services are explicitly registered in PHP-DI. Controllers are auto-wired.

Key services:
- `Database` — PDO wrapper with query builder, 5-second connection timeout
- `SettingsService` — reads/writes `settings` table, caches in memory
- `AuthService` — handles admin login (users table) and portal login (customer_portal_users table)
- `NayaxService` — Nayax Lynx API client with curl_multi parallel requests
- `CommissionService` — shared commission generation logic (used by controller + cron)
- `MailService` — SMTP email via PHPMailer
- `AuditService` — audit logging

### Middleware Stack

```
ErrorMiddleware → RoutingMiddleware → TwigMiddleware → BodyParsingMiddleware
  ↓ Admin routes: AuthMiddleware → CsrfMiddleware
  ↓ Portal routes: PortalAuthMiddleware → CsrfMiddleware
```

Custom error handler suppresses 404 stack traces (bot noise) and shows clean error pages.

### Authentication

Two separate auth systems:
- **Admin:** `users` table, session-based, `AuthMiddleware` checks `$_SESSION['user_id']`
- **Portal:** `customer_portal_users` table, session-based, `PortalAuthMiddleware` checks `$_SESSION['portal_user_id']`

## Key Features & Business Logic

### Commission Calculation (V3 Formula)

Commissions are calculated **per-machine**, each with its own rate:

1. For each machine owned by the customer:
   - Gross = Cash + Card (prepaid excluded)
   - Fees = card_transactions × processing_fee_rate
   - Net = Gross - Fees
   - Machine Commission = Net × machine_commission_rate%
2. Total Commission = Sum of all machine commissions
3. Deduct parts cost and labour cost
4. Apply carry forward from previous period
5. If negative, carry forward to next period (commission = $0)

**Key:** The `commission_rate` on the `customers` table is a **default** that auto-populates new machines. The actual calculation always uses `machines.commission_rate`.

**Code:** `src/Services/CommissionService.php` → `generateForCustomer()`

### Commission Workflow

```
Draft → Approved → Paid
  ↓        ↓
 Void    Void
```

- **Generate:** `/commissions/generate` (single) or `/commissions/generate-all` (bulk)
- **Recalculate:** updates amounts from current revenue data
- **Approve:** marks ready for payment
- **Pay:** records payment method + reference
- **Void:** reverts carry forward
- **Delete:** permanently removes commission + line items
- **Export PDF:** downloads A4 commission statement (admin + portal)
- **Export Xero CSV:** downloads all approved/paid as Xero bill import CSV

### Nayax Integration

**API:** Nayax Lynx Operational API (`swagger.json` in project root)
**Base URLs:** Production: `https://lynx.nayax.com/Operational`, QA: `https://qa-lynx.nayax.com/Operational`

**Flow:**
1. **Device Sync** — `GET /v1/machines` → upserts `nayax_devices` table
2. **Link Devices** — admin links nayax devices to PulseOPS machines via `/nayax/devices`
3. **Import Transactions** — `GET /v1/machines/{id}/lastSales` → stores in `nayax_transactions`
4. **Aggregate** — groups transactions by device+date → creates/updates `revenue` records

**Critical Limitation:** The `lastSales` endpoint has NO date parameters and returns a limited window of recent transactions. High-volume machines may lose older transactions. The cron runs every 15 minutes to minimize gaps.

**Payment Type Classification:**
- `cash`, `coin` → cash (only counted if `nayax_cash_counting_enabled` is on)
- `prepaid credit`, `monyx` → prepaid (excluded from commission gross)
- Everything else (card, QR, app, mifare) → card (commissioned)

**Reconciliation:** `/nayax/reconcile` compares imported transaction totals vs aggregated revenue per machine to identify gaps.

### Job Notes with Time Tracking

Job notes support:
- **Time minutes** — auto-added to job's total `labour_minutes`
- **Billable flag** — marks time as deductible from customer commission
- **Internal/Customer visible** — `is_internal=1` hides from portal, `is_internal=0` shows to customer
- Portal users can add notes (stored with `portal_user_id`)

### Customer Portal

Customers access `/portal/login` with credentials created by admin.

Portal features:
- Dashboard with revenue summary
- Machine list and detail
- Report machine issues (creates jobs)
- Revenue history
- Commission statements with PDF download
- Job tracking with notes
- Profile, password, bank detail management

**Welcome Email:** When admin creates a portal user, an HTML welcome email is auto-sent with login credentials via SMTP (requires SMTP configured in Settings > Email).

### Xero Integration

CSV export of approved/paid commissions formatted for Xero bill import.

**Settings (Settings > Commission > Xero):**
- Account Code (expense account)
- Tax Type (e.g. "GST on Expenses", "BAS Excluded")
- Payment Terms (days)

**CSV Format:** ContactName, InvoiceNumber, InvoiceDate, DueDate, Description, Quantity, UnitAmount, AccountCode, TaxType, Currency

**Import in Xero:** Business > Bills to pay > Import

## Cron Schedule

Configured in `docker/crontab`, run by crond via supervisord:

| Schedule | Script | Purpose |
|----------|--------|---------|
| Every 15 min | `nayax-transactions.php` | Import transactions from Nayax API |
| Every 30 min | `nayax-revenue.php` | Aggregate transactions into revenue |
| Every 6 hours | `nayax-sync.php` | Sync device data from Nayax |
| 1st @ 2:00 AM | `generate-commissions.php` | Auto-generate draft commissions for previous month |

All crons check `nayax_enabled` setting before running. Transaction import respects `nayax_cash_counting_enabled`.

## Database

Schema in `database/schema.sql`. Key tables:

| Table | Purpose |
|-------|---------|
| `users` | Admin users |
| `customers` | Customer accounts (business locations) |
| `customer_portal_users` | Portal login accounts per customer |
| `machines` | Amusement machines with commission_rate |
| `machine_types` | Machine type categories |
| `machine_photos` | Machine photo uploads |
| `revenue` | Revenue records (manual + nayax) |
| `commission_payments` | Commission statements per customer/period |
| `commission_line_items` | Manual adjustments on commissions |
| `maintenance_jobs` | Job/work orders |
| `job_notes` | Notes with time tracking + billable flag |
| `job_parts` | Parts used on jobs |
| `job_photos` | Job photo uploads |
| `job_statuses` | Configurable job status workflow |
| `nayax_devices` | Synced Nayax devices |
| `nayax_transactions` | Imported Nayax transactions |
| `nayax_imports` | Import history (manual + cron) |
| `settings` | Key-value app settings |
| `audit_logs` | Audit trail |

### Revenue Status ENUM
`draft` | `approved` | `rejected`

Manual entries default to `approved`. Only `approved` revenue is included in commission calculations.

### Commission Status ENUM
`draft` | `approved` | `paid` | `void`

### Migrations

Place SQL files in `database/migrations/`. The entrypoint runs them automatically on container start. They should be idempotent (use `IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, etc.).

## Known Issues & Technical Debt

### Opcache (CRITICAL)

The running container has `opcache.validate_timestamps=0` in php.ini, meaning PHP never checks if files changed on disk. This causes **504 timeouts after every deploy** because opcache serves stale bytecode.

**Current workaround:** `opcache_reset()` called on every request in `public/index.php`. This works but is wasteful.

**Permanent fix:** Rebuild the Docker container. The updated `docker/php/php.ini` has `validate_timestamps=1`. After rebuild:
1. Remove `opcache_reset()` from `public/index.php`
2. Remove `public/opcache-reset.php`
3. Remove `.deploy_version` file

### Container Rebuild Needed

The following changes require a Docker container rebuild to take effect:
- `docker/php/php.ini` — opcache settings
- `docker/crontab` — cron schedules
- `docker/supervisord.conf` — crond process
- `composer.json` — dompdf, phpmailer dependencies
- `docker/entrypoint.sh` — migration runner
- `database/migrations/` — new DB columns

### Nayax API Limitations

The `lastSales` endpoint returns a limited window of recent transactions with no date filtering. Machines with high transaction volume (200+/month) may lose older transactions. The 15-minute cron import schedule minimizes this but historical gaps cannot be recovered via the API.

### Template Pattern

Templates use **flat field references** (e.g., `job.machine_name`) not nested objects (e.g., `job.machine.name`). All data comes from SQL JOINs with aliases. When adding new templates or modifying existing ones, always check what field names the controller passes.

### Client-Side Sorting

All tables with `class="sortable"` get click-to-sort headers via JavaScript in `layouts/admin.twig`. Sorts numerically if values look like numbers, alphabetically otherwise.

## Settings Reference

Stored in `settings` table, accessed via `SettingsService::get()`.

| Key | Type | Description |
|-----|------|-------------|
| `default_commission_rate` | float | Default % for new machines |
| `default_processing_fee` | float | Per-transaction fee ($) |
| `labour_hourly_rate` | float | Default labour rate |
| `nayax_enabled` | boolean | Enable Nayax integration |
| `nayax_api_token` | string | Nayax API bearer token |
| `nayax_operator_id` | string | Nayax operator ID |
| `nayax_environment` | string | `production` or `qa` |
| `nayax_cash_counting_enabled` | boolean | Count cash from Nayax |
| `nayax_auto_import` | boolean | Enable auto-import cron |
| `nayax_import_interval` | string | Minutes between imports |
| `nayax_import_days` | string | Days back to import |
| `nayax_cron_key` | string | API cron endpoint key |
| `xero_account_code` | string | Xero expense account code |
| `xero_tax_type` | string | Xero tax type for bills |
| `xero_due_days` | string | Payment terms in days |
| `smtp_host` | string | SMTP server |
| `smtp_port` | string | SMTP port |
| `smtp_username` | string | SMTP username |
| `smtp_password` | string | SMTP password |
| `smtp_encryption` | string | `tls` or `ssl` |
| `smtp_from_name` | string | Email sender name |
| `company_name` | string | Company name for PDFs |

## Development

### Local Setup

```bash
cp .env.example .env  # Configure DB credentials
composer install
# Start with Docker:
docker compose up -d
# Or PHP built-in server:
php -S localhost:8080 -t public
```

### Adding a New Feature

1. Add route in `config/routes.php`
2. Add controller method in `src/Controllers/`
3. Add template in `templates/admin/` or `templates/portal/`
4. If new DB columns needed, create `database/migrations/*.sql`
5. Update `database/schema.sql` for fresh installs

### Common Gotchas

- **ENUM values:** Check schema.sql before using string values in insert/update. Mismatched ENUMs cause silent failures or truncation errors.
- **Column names:** `job_notes.user_id` (not `created_by`), `nayax_imports.import_date` (not `created_at`), `commission_payments.total_prepaid` (not `prepaid_amount`)
- **Route params:** Match `{paramName}` in routes to `$args['paramName']` in controllers exactly
- **fputcsv:** PHP 8.4 requires the escape parameter: `fputcsv($f, $row, ',', '"', '\\')`
- **Settings types:** `SettingsService::get()` returns cast values (boolean for `'1'`/`'0'`), not raw strings. Don't compare with `=== 'true'`.

## Deployment

Deployed via Coolify to Docker. The Dockerfile builds everything.

After any deploy:
1. Visit `/opcache-reset.php` to clear opcache (until container rebuild)
2. Check `/logs` for errors
3. If DB columns added, container restart triggers migrations via entrypoint.sh

### Environment Variables

```env
APP_NAME=PulseOPS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://v2.pulseops.com.au
APP_TIMEZONE=Australia/Darwin
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=pulseops
DB_USERNAME=pulseops
DB_PASSWORD=secret
NAYAX_API_URL=https://lynx.nayax.com/Operational
NAYAX_API_TOKEN=your-token
NAYAX_OPERATOR_ID=your-id
NAYAX_ENVIRONMENT=production
```
