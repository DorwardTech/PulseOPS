# PulseOPS API & Endpoint Reference

This document catalogues every HTTP endpoint exposed by PulseOPS V3. PulseOPS is primarily a server-rendered web application (Slim 4 + Twig), so most endpoints return HTML or 302 redirects rather than JSON. The only true machine-readable endpoints are the health check (`/healthz`), the Nayax cron hook (`/api/nayax/cron`), and the Nayax diagnostics page (`/nayax/diagnostics`).

- **Base URL (production):** `https://v2.pulseops.com.au`
- **Framework:** Slim 4, PHP 8.4
- **Content type:** `text/html; charset=utf-8` unless stated otherwise
- **Route definitions:** `config/routes.php`

---

## 1. Authentication

PulseOPS has three separate authentication surfaces:

| Surface | Scope | Auth mechanism | Middleware |
|---|---|---|---|
| Admin | `/*` (ungrouped admin routes) | Session cookie — `$_SESSION['user_id']` | `AuthMiddleware` + `CsrfMiddleware` |
| Customer Portal | `/portal/*` | Session cookie — `$_SESSION['portal_user_id']` | `PortalAuthMiddleware` + `CsrfMiddleware` |
| Cron API | `GET /api/nayax/cron` | Shared secret — `?key=<nayax_cron_key>` | None (validated in controller) |

### 1.1 Session login

Admin and portal each expose a standard HTML form that POSTs credentials and sets a PHP session cookie. Log in first at `POST /login` (admin) or `POST /portal/login` (customer), then send the resulting `PHPSESSID` cookie on subsequent requests.

### 1.2 CSRF

All state-changing admin/portal requests (`POST`, `PUT`, `PATCH`, `DELETE`) must include the CSRF token, either:

- As form field `csrf_token`, **or**
- As header `X-CSRF-TOKEN`

The expected value lives in `$_SESSION['csrf_token']` and is rendered into every template as `{{ csrf_token }}`. A mismatch returns `403` with JSON `{"error":"CSRF token mismatch"}`. Safe methods (`GET`, `HEAD`, `OPTIONS`) bypass the check.

### 1.3 Permissions

Beyond "logged in or not", admin routes enforce per-action permissions through role records on the `users` table. A few endpoints explicitly gate on the `*` (super-admin) permission — those are flagged below.

---

## 2. Response conventions

Because PulseOPS is a classic server-rendered app, most mutation endpoints follow the POST-Redirect-GET pattern:

- **Success:** `302` redirect (typically to the resource `show` page) with `$_SESSION['flash_success']` set.
- **Validation failure:** `302` redirect back to the form with `$_SESSION['flash_error']` set.
- **Not found:** `302` redirect to the resource index with a flash error. (No JSON 404 is returned from controllers.)

Exceptions:

- `/healthz`, `/api/nayax/cron`, and `/nayax/diagnostics` return `application/json`.
- `/commissions/{id}/pdf`, `/portal/commissions/{id}/pdf` return `application/pdf`.
- CSV exports (`/commissions/export-xero`, `/analytics/export`) return `text/csv` as a download.
- Unhandled 404s/exceptions are served as HTML by the custom error handler in `config/middleware.php`.

### 2.1 Status ENUMs referenced below

| Table | Statuses |
|---|---|
| `revenue` | `draft`, `approved`, `rejected` |
| `commission_payments` | `draft`, `approved`, `paid`, `void` |
| `machines` | `active`, `inactive`, `storage`, `retired` (set via `status` form field) |
| `maintenance_jobs` | Configurable via `job_statuses` table; referenced by `status_id` |

---

## 3. Health & public endpoints

### `GET /healthz`

Lightweight liveness check. No auth, no DB, no Twig.

**Response** — `200 application/json`:
```json
{ "status": "ok", "time": "2026-04-21T12:34:56+09:30" }
```

### `GET /login` · `POST /login` · `GET /logout`

Admin login flow.

| Method/Path | Body / Query | Response |
|---|---|---|
| `GET /login` | — | HTML `admin/auth/login.twig` (redirects to `/dashboard` if already authed) |
| `POST /login` | `email`, `password`, `remember` | `302 /dashboard` on success, else `302 /login` |
| `GET /logout` | — | `302 /login`, session cleared |

### `GET /portal/login` · `POST /portal/login` · `GET /portal/logout`

Portal login flow — identical shape, but scoped to `customer_portal_users`.

| Method/Path | Body / Query | Response |
|---|---|---|
| `GET /portal/login` | — | HTML `portal/auth/login.twig` |
| `POST /portal/login` | `email`, `password` | `302 /portal/dashboard` on success, else `302 /portal/login` |
| `GET /portal/logout` | — | `302 /portal/login` |

---

## 4. Cron API — the only key-authenticated JSON endpoint

### `GET /api/nayax/cron`

Triggers a transaction import + revenue aggregation from Nayax. Intended to be called by an external scheduler; the in-container crond uses its own bootstrap script instead.

**Auth:** `?key=<value>` must equal the `nayax_cron_key` setting (compared with `hash_equals`).

**Query params:**

| Name | Required | Notes |
|---|---|---|
| `key` | yes | Shared secret |

**Guards:**
- `401 { "error": "Unauthorized" }` if key is missing/wrong or `nayax_cron_key` is empty.
- `200 { "status": "disabled", "message": "Auto-import is disabled" }` if setting `nayax_auto_import` is false.
- `200 { "status": "skipped", "message": "Not yet due", "next_in": "<seconds>s" }` if the last cron import ran less than `nayax_import_interval` minutes ago.

**Success response** — `200 application/json`:
```json
{
  "status": "success",
  "imported": 12,
  "skipped": 3,
  "aggregated": 5,
  "period": "2026-04-14 to 2026-04-21"
}
```

**Error response** — `200 application/json` (errors are caught, logged to `nayax_imports`, and returned inline):
```json
{ "status": "error", "message": "<exception message>" }
```

---

## 5. Admin endpoints

All paths in this section require a valid admin session and a CSRF token on mutations. Unless stated otherwise, a successful `POST`/`DELETE` returns `302` to a related page with `flash_success`.

### 5.1 Dashboard

| Method | Path | Params | Response |
|---|---|---|---|
| `GET` | `/` | — | HTML `admin/dashboard/index.twig` |
| `GET` | `/dashboard` | — | HTML `admin/dashboard/index.twig` |

Renders revenue stats, top machines, recent jobs, and six-month chart data.

### 5.2 Machines

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/machines` | query: `page`, `per_page` (default 25), `status`, `customer_id`, `machine_type_id`, `search`, `view` | HTML list |
| `GET` | `/machines/create` | — | HTML form |
| `POST` | `/machines` | body: `machine_code`, `name`, `customer_id`, `machine_type_id`, `description`, `location_details`, `status`, `serial_number`, `manufacturer`, `model`, `nayax_cash_counting`, `commission_rate`, `notes` | `302 /machines/{id}` |
| `GET` | `/machines/{id}` | — | HTML detail (revenue history, photos, jobs, Nayax link) |
| `GET` | `/machines/{id}/edit` | — | HTML edit form |
| `POST` | `/machines/{id}` | same fields as `store` | `302 /machines/{id}` |
| `POST`/`DELETE` | `/machines/{id}` (delete) | — | `302 /machines` |
| `GET` | `/machines/import` | — | HTML import form |
| `POST` | `/machines/import` | `multipart/form-data` file `csv_file` (columns: `machine_code, name, customer, machine_type, description, location_details, status, serial_number, manufacturer, model`) | `302 /machines` |
| `POST` | `/machines/{id}/photos` | `multipart/form-data` file `photo`, body `caption` | `302 /machines/{id}` |
| `POST`/`DELETE` | `/machines/{id}/photos/{photoId}` | — | `302 /machines/{id}` |

Notes: `commission_rate` defaults from the customer's default rate if left blank on create. All writes are audited via `AuditService`.

### 5.3 Customers

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/customers` | query: `page`, `per_page`, `search` | HTML list (searches name, contact, email, ABN) |
| `GET` | `/customers/create` | — | HTML form |
| `POST` | `/customers` | body: `name`, `contact_name`, `email`, `phone`, `mobile`, `address_line1`, `address_line2`, `city`, `state`, `postcode`, `country`, `abn`, `commission_rate`, `processing_fee`, `payment_terms`, `payment_method`, `bank_name`, `bank_bsb`, `bank_account_number`, `bank_account_name`, `notes` | `302 /customers/{id}` |
| `GET` | `/customers/{id}` | — | HTML detail (machines, revenue, commissions, portal users) |
| `GET` | `/customers/{id}/edit` | — | HTML edit form |
| `POST` | `/customers/{id}` | same fields as `store` | `302 /customers/{id}` (rate changes recorded in `commission_rate_history`) |
| `POST`/`DELETE` | `/customers/{id}` (delete) | — | `302 /customers` |
| `GET` | `/customers/import` | — | HTML import form |
| `POST` | `/customers/import` | file `csv_file` | `302 /customers` |
| `GET` | `/customers/{id}/portal` | — | HTML list of portal users |
| `POST` | `/customers/{id}/portal` | body: `name`, `email`, `password` | `302 /customers/{id}/portal` (also triggers welcome email via SMTP) |
| `POST` | `/customers/{id}/portal/{userId}/toggle` | — | `302 /customers/{id}/portal` (flips `is_active`) |

### 5.4 Jobs

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/jobs` | query: `page`, `status_id`, `machine_id`, `priority`, `assigned_to`, `search`, `show_all` | HTML list (hides closed/completed unless `show_all=1`) |
| `GET` | `/jobs/create` | — | HTML form |
| `POST` | `/jobs` | body: `machine_id`, `status_id`, `job_type`, `priority`, `title`, `description`, `assigned_to`, `scheduled_date`, `scheduled_time`, `labour_minutes`, `labour_rate`, `is_customer_visible` | `302 /jobs/{id}` (auto-generates `job_number`, calculates `labour_cost`) |
| `GET` | `/jobs/{id}` | — | HTML detail with notes/photos/parts |
| `GET` | `/jobs/{id}/edit` | — | HTML edit form |
| `POST` | `/jobs/{id}` | same fields as store | `302 /jobs/{id}` (recomputes `parts_cost`, `total_cost`) |
| `POST`/`DELETE` | `/jobs/{id}` (delete) | — | `302 /jobs` (cascades notes/parts/photos) |
| `POST` | `/jobs/{id}/notes` | body: `note`, `time_minutes`, `is_billable`, `is_customer_visible` | `302 /jobs/{id}` (adds minutes to `labour_minutes`) |
| `POST` | `/jobs/{id}/photos` | file `photo`, body `caption` | `302 /jobs/{id}` |
| `POST`/`DELETE` | `/jobs/{id}/photos/{photoId}` | — | `302 /jobs/{id}` |
| `POST` | `/jobs/{id}/parts` | body: `part_name`, `part_number`, `quantity`, `unit_cost` | `302 /jobs/{id}` |
| `POST`/`DELETE` | `/jobs/{id}/parts/{partId}` | — | `302 /jobs/{id}` |
| `POST` | `/jobs/{id}/status` | body: `status_id` | `302 /jobs/{id}` |

### 5.5 Revenue

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/revenue` | query: `page`, `per_page`, `date_from`, `date_to`, `machine_id`, `customer_id`, `source`, `status` | HTML list |
| `GET` | `/revenue/create` | — | HTML form |
| `POST` | `/revenue` | body: `machine_id`, `collection_date`, `cash_amount`, `card_amount`, `prepaid_amount`, `card_transactions`, `prepaid_transactions`, `cash_source`, `source`, `status`, `notes` | `302 /revenue/{id}` |
| `GET` | `/revenue/{id}` | — | HTML detail |
| `GET` | `/revenue/{id}/edit` | — | HTML edit form (blocked for `source='nayax'`) |
| `POST` | `/revenue/{id}` | same fields as store | `302 /revenue/{id}` |
| `POST`/`DELETE` | `/revenue/{id}` (delete) | — | `302 /revenue` |
| `POST` | `/revenue/{id}/approve` | — | `302 /revenue/{id}` (sets `status=approved`, records approver) |
| `POST` | `/revenue/approve-all` | — | `302 /revenue` (bulk approve drafts) |
| `GET` | `/revenue/by-machine` | query: `date_from`, `date_to` | HTML aggregation view |
| `GET` | `/revenue/import` | — | HTML import form |
| `POST` | `/revenue/import` | file `csv_file` (columns: `machine_id, collection_date, cash_amount, card_amount, prepaid_amount, card_transactions, prepaid_transactions, cash_source`) | `302 /revenue` |

Status coercion: form value `verified` → DB `approved`, `pending` → `draft`. Manual entries default to `approved`; CSV imports default to `draft`.

### 5.6 Commissions

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/commissions` | query: `page`, `per_page`, `customer_id`, `status`, `period_start`, `period_end` | HTML list |
| `GET` | `/commissions/generate` | — | HTML form |
| `POST` | `/commissions/generate` | body: `customer_id`, `period_start`, `period_end` | `302 /commissions/{id}` — calls `CommissionService::generateForCustomer` |
| `POST` | `/commissions/generate-all` | body: `period_start`, `period_end` | `302 /commissions` — skips customers already `approved`/`paid` for the period |
| `GET` | `/commissions/{id}` | — | HTML detail (per-machine breakdown, line items) |
| `GET` | `/commissions/{id}/pdf` | — | `application/pdf` download `commission_{customer}_{date}.pdf` (Dompdf → `pdf/commission.twig`) |
| `POST` | `/commissions/{id}/approve` | — | `302 /commissions/{id}` |
| `POST` | `/commissions/{id}/pay` | body: `payment_method`, `payment_reference` | `302 /commissions/{id}` (sets `paid_at`) |
| `POST` | `/commissions/{id}/void` | — | `302 /commissions/{id}` (reverts `customers.carry_forward`) |
| `POST`/`DELETE` | `/commissions/{id}` (delete) | — | `302 /commissions` |
| `POST` | `/commissions/{id}/line-items` | body: `description`, `amount`, `type` | `302 /commissions/{id}` |
| `POST`/`DELETE` | `/commissions/{id}/line-items/{itemId}` | — | `302 /commissions/{id}` |
| `POST` | `/commissions/{id}/recalculate` | — | `302 /commissions/{id}` |
| `GET` | `/commissions/export-xero` | query: `status`, `period_start`, `period_end` | `text/csv` download `xero_commissions_{date}.csv` (ContactName, InvoiceNumber, InvoiceDate, DueDate, Description, Quantity, UnitAmount, AccountCode, TaxType, Currency) |

### 5.7 Nayax

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/nayax` | — | HTML overview |
| `GET` | `/nayax/devices` | — | HTML device list + linking UI |
| `POST` | `/nayax/devices/sync` | — | `302 /nayax/devices` — pulls devices from Nayax Lynx API |
| `POST` | `/nayax/sync-devices` | — | Alias of the above |
| `POST` | `/nayax/devices/bulk-link` | body: `links[device_id]`, `links[machine_id]` (parallel arrays) | `302 /nayax/devices` |
| `POST` | `/nayax/devices/{id}/link` | body: `machine_id` | `302 /nayax/devices` |
| `POST` | `/nayax/devices/{id}/unlink` | — | `302 /nayax/devices` |
| `GET` | `/nayax/transactions` | query: `page`, `date_from`, `date_to`, `device_id`, `payment_type` | HTML list |
| `GET` | `/nayax/import` | — | HTML import form + recent import history |
| `POST` | `/nayax/import` | body: `date_from`, `date_to` | `302 /nayax/import` (import + aggregation) |
| `POST` | `/nayax/import-transactions` | Alias of the above | |
| `POST` | `/nayax/reaggregate` | — | `302 /nayax/import` (wipes & rebuilds Nayax revenue rows) |
| `GET` | `/nayax/diagnostics` | — | `application/json` — distinct PaymentMethod/RecognitionMethod, current payment_type distribution, revenue-by-source counts |
| `GET` | `/nayax/reconcile` | query: `date_from`, `date_to` | HTML reconciliation report |

### 5.8 Analytics

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/analytics` | — | HTML overview (12-month trends, MTD/YTD, charts) |
| `GET` | `/analytics/revenue` | query: `date_from`, `date_to`, `group_by` (`month`/`machine`/`customer`) | HTML |
| `GET` | `/analytics/machines` | query: `date_from`, `date_to` | HTML |
| `GET` | `/analytics/customers` | query: `date_from`, `date_to` | HTML |
| `GET` | `/analytics/export` | query: `type` (`revenue`/`machines`/`customers`), `date_from`, `date_to` | `text/csv` download `analytics_{type}_{date}.csv` |

### 5.9 Settings

All settings writes redirect back to the corresponding tab (`/settings?tab=<tab>`).

| Method | Path | Input |
|---|---|---|
| `GET` | `/settings` | query: `tab` |
| `POST` | `/settings/general` | `company_name`, `company_email`, `company_phone`, `company_address`, `timezone`, `currency` |
| `POST` | `/settings/commission` | `default_commission_rate`, `default_processing_fee`, `labour_hourly_rate`, `labour_increment_minutes`, `xero_account_code`, `xero_tax_type`, `xero_due_days` |
| `POST` | `/settings/nayax` | `nayax_enabled`, `nayax_api_token`, `nayax_operator_id`, `nayax_environment`, `nayax_cash_counting_enabled`, `nayax_auto_import`, `nayax_import_interval`, `nayax_import_days`, `nayax_cron_key` (auto-generated if blank) |
| `POST` | `/settings/email` | `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_encryption`, `smtp_from_name`, `smtp_from_address` |

User/role/job-status/machine-type management (all standard CRUD, body fields in parentheses):

| Method | Path | Body |
|---|---|---|
| `GET` | `/settings/users` | — |
| `GET` | `/settings/users/create` | — |
| `POST` | `/settings/users` | `full_name`, `email`, `password`, `phone`, `role_id`, `is_active` |
| `GET` | `/settings/users/{id}/edit` | — |
| `POST` | `/settings/users/{id}` | same as create (password optional on update) |
| `POST`/`DELETE` | `/settings/users/{id}` (delete) | — (blocks self-deletion) |
| `GET` | `/settings/roles` | — |
| `POST` | `/settings/roles` | `name`, `slug`, `permissions[]` |
| `POST` | `/settings/roles/{id}` | `name`, `slug`, `permissions[]` |
| `POST`/`DELETE` | `/settings/roles/{id}` (delete) | — (blocks if system role or users assigned) |
| `GET` | `/settings/job-statuses` | — |
| `POST` | `/settings/job-statuses` | `name`, `slug`, `color`, `sort_order`, `is_default` |
| `POST` | `/settings/job-statuses/{id}` | same |
| `POST`/`DELETE` | `/settings/job-statuses/{id}` (delete) | — (blocks if jobs assigned) |
| `GET` | `/settings/machine-types` | — |
| `POST` | `/settings/machine-types` | `name`, `description` |
| `POST` | `/settings/machine-types/{id}` | `name`, `description` |
| `POST`/`DELETE` | `/settings/machine-types/{id}` (delete) | — (blocks if machines assigned) |
| `GET` | `/settings/profile` | — |
| `POST` | `/settings/profile` | `full_name`, `email`, `phone` |
| `POST` | `/settings/profile/password` | `current_password`, `new_password`, `confirm_password` |

**Super-admin only (`*` permission):**

| Method | Path | Input | Notes |
|---|---|---|---|
| `GET` | `/settings/purge-data` | — | Shows row counts for revenue / commission_payments / nayax_transactions |
| `POST` | `/settings/purge-data` | `targets[]`, `confirm` (must equal `PURGE`) | Truncates selected tables, writes to `activity_logs` |

### 5.10 Logs (super-admin only)

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/logs` | query: `tab` (`php`/`api`), `lines`, `search`, `page`, `status`, `method`, `endpoint` | HTML viewer (PHP tab reads `error.log` up to 500 lines / 5 MB; API tab reads `nayax_api_logs`) |
| `POST` | `/logs/php/clear` | — | `302 /logs?tab=php` (truncates file if writable) |
| `POST` | `/logs/api/clear` | body: `days` | `302 /logs?tab=api` (deletes rows older than N days) |

---

## 6. Customer portal endpoints (`/portal/*`)

Requires a portal session cookie. All writes require CSRF. Portal users are scoped to a single customer, and every controller method re-verifies ownership (machine, commission, job) before rendering detail pages.

| Method | Path | Input | Response |
|---|---|---|---|
| `GET` | `/portal/dashboard` | — | HTML overview (machines, recent revenue, commission summary, open jobs) |
| `GET` | `/portal/machines` | — | HTML list scoped to customer |
| `GET` | `/portal/machines/{id}` | — | HTML detail (verifies ownership) |
| `GET` | `/portal/machines/{id}/report-issue` | — | HTML form |
| `POST` | `/portal/machines/{id}/report-issue` | body: `priority`, `title`, `description` | `302 /portal/machines/{id}` — creates `maintenance_jobs` row with `is_customer_visible=1`, `reported_by_customer=<portal_user_id>` |
| `GET` | `/portal/revenue` | query: `page`, `date_from`, `date_to` | HTML |
| `GET` | `/portal/commissions` | query: `page` | HTML |
| `GET` | `/portal/commissions/{id}` | — | HTML detail (per-machine breakdown) |
| `GET` | `/portal/commissions/{id}/pdf` | — | `application/pdf` download |
| `GET` | `/portal/jobs` | — | HTML — only jobs with `is_customer_visible=1` |
| `GET` | `/portal/jobs/{id}` | — | HTML detail (internal notes filtered) |
| `POST` | `/portal/jobs/{id}/notes` | body: `note` | `302 /portal/jobs/{id}` — inserts note with `is_internal=0`, `portal_user_id=<user>` |
| `GET` | `/portal/settings` | — | HTML |
| `POST` | `/portal/settings/profile` | body: `name`, `email`, `phone` | `302 /portal/settings` |
| `POST` | `/portal/settings/password` | body: `current_password`, `new_password`, `confirm_password` | `302 /portal/settings` |
| `POST` | `/portal/settings/bank` | body: `bank_name`, `bank_account_name`, `bank_bsb`, `bank_account_number` | `302 /portal/settings` — updates `customers` row |

---

## 7. Example requests

### 7.1 Health check
```bash
curl -s https://v2.pulseops.com.au/healthz
# {"status":"ok","time":"2026-04-21T12:34:56+09:30"}
```

### 7.2 Triggering the Nayax cron
```bash
curl -s "https://v2.pulseops.com.au/api/nayax/cron?key=$NAYAX_CRON_KEY"
```

### 7.3 Authenticated admin POST (full round-trip)

```bash
# 1. Fetch login page to get PHPSESSID + CSRF token
curl -s -c cookies.txt https://v2.pulseops.com.au/login > /dev/null

# 2. POST credentials (CSRF is generated lazily per session; fetch the login form
#    HTML to scrape the hidden csrf_token field or rely on a subsequent GET).
curl -s -b cookies.txt -c cookies.txt \
  -d "email=admin@example.com&password=secret&csrf_token=$TOKEN" \
  https://v2.pulseops.com.au/login

# 3. GET a page that renders csrf_token (e.g. /machines/create) and scrape
#    the value from the form before making mutations.

# 4. Issue a mutation — CSRF in header works equally well:
curl -s -b cookies.txt \
  -H "X-CSRF-TOKEN: $CSRF" \
  -d "machine_code=M-042&name=New+Machine&status=active" \
  https://v2.pulseops.com.au/machines
```

Portal requests follow the same shape against `/portal/*`.

---

## 8. Error handling

- **Validation & business errors:** 302 back to form with `flash_error`.
- **CSRF failure:** `403 application/json` — `{"error":"CSRF token mismatch"}`.
- **Cron auth failure:** `401 application/json` — `{"error":"Unauthorized"}`.
- **Route not found:** custom HTML 404 (stack traces suppressed to reduce bot noise) — see `config/middleware.php`.
- **Uncaught exception:** generic HTML 500; full trace is recorded in the PHP error log and visible at `/logs` for super-admins.

---

## 9. Rate limiting & pagination

- **Rate limiting:** not implemented in the application layer. Any throttling must be provided by the reverse proxy (nginx / Coolify).
- **Pagination:** list endpoints accept `page` (1-based) and, where applicable, `per_page` (default 25, no hard cap). Totals are embedded in the rendered HTML, not returned as headers.

---

## 10. Changelog

Add entries here when endpoints are added, changed, or removed. Keep synchronised with `config/routes.php`.
