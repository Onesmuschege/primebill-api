# PrimeBill API

> PrimeBill API is the backend engine powering the PrimeBill ISP Billing System. It provides a comprehensive REST API covering subscriber management, automated billing, M-Pesa Daraja payment processing, MikroTik RouterOS integration, FreeRADIUS synchronization, SMS/Email/WhatsApp notifications, vouchers, loyalty & referrals, FUP enforcement, and real-time network monitoring tailored for the Kenyan ISP market.

![Laravel](https://img.shields.io/badge/Laravel-12.x-red) ![PHP](https://img.shields.io/badge/PHP-8.3-blue) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18.x-336791?logo=postgresql&logoColor=white) ![Redis](https://img.shields.io/badge/Redis-compat-brightgreen) ![License](https://img.shields.io/badge/License-Proprietary-lightgrey)

---

## Table of Contents
- [Overview](#overview)
- [Key Features](#key-features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure-high-level)
- [Database Schema](#database-schema-summary)
- [Prerequisites](#prerequisites)
- [Getting Started](#getting-started-development)
- [Environment Variables](#environment-variables)
- [Running in Production](#running-in-production-deployment-notes)
- [API Endpoints](#api-endpoints-summary)
- [Scheduled Jobs & Queue](#scheduled-jobs--queue)
- [Testing](#testing)
- [Known Issues / Tech Debt](#known-issues--tech-debt)
- [Default Credentials](#default-credentials)
- [Contributing & Support](#contributing--support)
- [Related Repositories](#related-repositories)
- [License](#license)
- [Author](#author)

---

## Overview

PrimeBill API is a Laravel 12 application implementing billing and network management features for ISPs in Kenya. It exposes an admin/staff REST API, a client self-service portal API, and a public captive-portal API for hotspot/voucher payment flows. Core responsibilities include subscriber lifecycle management, invoicing, M-Pesa STK Push/C2B payments with idempotency protection, MikroTik RouterOS provisioning via an adapter interface, FreeRADIUS sync and accounting ingestion, FUP (fair usage) enforcement, vouchers, a loyalty-points/referral engine, SMS/Email/WhatsApp notifications, ticketing with escalation, and finance/inventory tracking.

> **Database:** PrimeBill runs on **PostgreSQL 18** for multi-tenant-ready isolation, exact numeric precision on financial transactions, JSONB support for M-Pesa callback payloads, and Railway deployment compatibility.

---

## Key Features

- **Authentication:** Laravel Sanctum token-based auth with role & permission management (Spatie Permissions) across four roles — `super_admin`, `admin`, `staff`, `client`.
- **Subscriber lifecycle:** Create/update/delete clients, suspend/activate accounts, manage PPPoE/Hotspot credentials, per-account service status.
- **Plans & services:** PPPoE, Hotspot, and static-IP plans with FUP and burst speed/upload-download configuration.
- **Invoicing:** Auto-numbered invoices, bulk-generation, per-client invoice/payment/balance history. (No PDF export is implemented yet — see [Known Issues](#known-issues--tech-debt).)
- **Payments:** Cash/bank/M-Pesa payments with automatic invoice reconciliation, daily summaries, and idempotency-key deduplication.
- **M-Pesa Daraja:** STK Push initiation, C2B validation/confirmation, IP-allowlist + HMAC signature verification on callbacks (`VerifyMpesaCallback` middleware), sandbox & production modes.
- **Vouchers:** Batch generation, stats, redemption (including a public captive-portal redeem endpoint), batch listing.
- **Loyalty & referrals:** Points awarding/redemption, leaderboard, referral code generation/backfill and join/stats tracking.
- **FUP engine:** Per-account throttle status, stats, and manual reset, logged to `fup_logs`.
- **MikroTik integration:** RouterOS API via a `RouterAdapterInterface` (real `MikroTikRouterAdapter` + a `MockRouterAdapter` for local/dev), connection testing, resource/session polling, provisioning jobs.
- **FreeRADIUS sync:** RADIUS user sync via a `RadiusAdapterInterface` (`FreeRadiusAdapter` + `MockRadiusAdapter`), accounting session ingestion via an authenticated webhook, and a RADIUS settings/test panel.
- **Notifications:** SMS via a pluggable gateway interface (Africa's Talking, Hostpinnacle), branded HTML email via `EmailService`, and WhatsApp messaging over Africa's Talking's Business Messaging API.
- **Ticketing:** Full lifecycle with threaded replies, assignment, closing, and escalation.
- **Dashboard & analytics:** KPIs, traffic graphs, top bandwidth users, monthly revenue/client-growth/payment-method/plan-distribution analytics.
- **Finance & inventory:** Expense tracking with category summaries, sales commissions (approve/pay), equipment inventory with assignment and low-stock alerts.
- **Admin management:** In-app user and role/permission management (`AdminUserController`, `AdminRoleController`) instead of only seeded roles.
- **Audit logs & settings:** Full audit trail with CSV export and a key-value settings store (company info, M-Pesa, SMS, logo upload).
- **Scheduled jobs and queues:** Automated invoicing, reminders, overdue suspension, M-Pesa reconciliation, paid-account reactivation, router traffic polling, and RADIUS sync — all queue/Redis-backed.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP 8.3, `^8.2` minimum) |
| Database | **PostgreSQL 18** |
| Cache & Queue | Redis |
| API Auth | Laravel Sanctum 4 |
| RBAC | Spatie Permissions 6 |
| Network | `evilfreelancer/routeros-api-php` (MikroTik RouterOS API) |
| Payments | Safaricom Daraja (M-Pesa) |
| SMS / WhatsApp | Africa's Talking / Hostpinnacle |
| Testing | PHPUnit 11 + Pest plugin, Mockery |
| Package Manager | Composer 2.x |

> PDF generation (invoices, receipts) is **not currently a dependency** — earlier planning docs referenced DomPDF/mPDF, but neither is installed and no PDF-generation code exists in `app/`. Treat any references to invoice PDF export in older docs as aspirational, not shipped.

---

## Project Structure (high-level)

| Directory | Purpose |
|---|---|
| `app/Console/Commands` | Scheduled artisan commands (invoicing, suspension, polling, RADIUS sync, referral backfill, cleanup) |
| `app/Http/Controllers/Api` | Admin/Staff API controllers (29 controllers) |
| `app/Http/Controllers/Portal` | Client portal + public captive-portal controllers (9 controllers) |
| `app/Http/Middleware` | `VerifyMpesaCallback` / `ValidateMpesaCallback`, rate limiter, trust proxies |
| `app/Jobs` | Queued jobs — provisioning, activation/suspension, SMS, RADIUS accounting |
| `app/Models` | 24 Eloquent models covering billing, network, support, engagement domains |
| `app/Services` | Business logic organized by domain (see below) |
| `config/` | Configuration for `mpesa.php`, `sms.php`, `cors.php`, RouterOS/RADIUS connections |
| `database/migrations` | 34 schema migrations |
| `database/seeders` | 16 seeders for roles, admin/staff, plans, clients, invoices, payments, demo data |
| `routes/api.php` | ~157 API route definitions |
| `routes/console.php` | Scheduled command definitions |

### Services by domain

```
app/Services/
├── Analytics/      AnalyticsService
├── Billing/         BalanceService, IdempotencyService, InvoiceService,
│                     LedgerService, PaymentService, SubscriptionService, VoucherService
├── Client/           ClientService
├── Communication/    EmailService, WhatsAppService
├── Dashboard/         DashboardService
├── Email/             EmailService  (duplicate namespace — see Known Issues)
├── Finance/            CommissionService, ExpenditureService
├── Inventory/           InventoryService
├── Mpesa/                MpesaService
├── Network/               MikroTikRouterAdapter, MikroTikService, MockRouterAdapter,
│                           ProvisioningService, RouterAdapterInterface, RouterService
├── Plan/                   PlanService
├── Radius/                  FreeRadiusAdapter, MockRadiusAdapter, RadiusAdapterInterface
├── Reporting/                ReportService
├── Settings/                  SettingsService
├── Sms/                        SmsService, Gateways/{AfricasTalkingGateway, HostpinnacleGateway, SmsGatewayInterface}
└── Support/                     TicketService
```

---

## Database Schema (summary)

| Table | Description |
|---|---|
| `users` | Admin/staff accounts |
| `clients` | Subscriber profiles (now with referral columns) |
| `client_accounts` | PPPoE/Hotspot credentials per client |
| `plans` | Service plans (with burst/upload/download speed fields) |
| `routers` | MikroTik router configurations |
| `invoices` | Billing invoices |
| `payments` | Recorded payments (M-Pesa, cash, bank transfers) |
| `ledger_entries` | Double-entry financial ledger |
| `idempotency_keys` | Payment deduplication keys |
| `tickets` / `ticket_replies` | Support ticketing |
| `sms_logs` | SMS delivery logs |
| `expenditures` | Expense records |
| `inventory_items` | Equipment inventory |
| `network_traffic` | Router Tx/Rx polled data |
| `radius_sessions` | FreeRADIUS accounting sessions ingested via webhook |
| `radcheck` / `radreply` / `radusergroup` | Native FreeRADIUS tables |
| `sales_commissions` | Staff commissions |
| `fup_logs` | FUP enforcement events |
| `vouchers` | Prepaid voucher batches, codes, and redemption state |
| `loyalty_points` | Client loyalty point balances |
| `system_logs` | Full audit trail |
| `settings` | Key-value application settings |
| `notifications` | In-app notifications |

---

## Prerequisites

- PHP 8.2+ (developed against 8.3) with extensions: `pdo_pgsql`, `pgsql`, `sockets`, `zip`
- Composer 2.x
- **PostgreSQL 18** (see setup below)
- Redis
- A webserver (Nginx/Apache) and PHP-FPM in production
- Optional: ngrok (for local M-Pesa callback testing)

---

## Getting Started (development)

### 1. Clone the repository

```bash
git clone https://github.com/Onesmuschege/primebill-api.git
cd primebill-api
```

### 2. Install PHP dependencies

```bash
composer install
```

Enable required PHP extensions in your `php.ini`:
```ini
extension=pdo_pgsql
extension=pgsql
extension=sockets
extension=zip
```

### 3. Set up PostgreSQL

```sql
CREATE DATABASE primebill;
CREATE USER primebill_user WITH PASSWORD 'StrongPass@123';
GRANT ALL PRIVILEGES ON DATABASE primebill TO primebill_user;
\c primebill
GRANT ALL ON SCHEMA public TO primebill_user;
```

### 4. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

The shipped `.env.example` defaults to SQLite/database-driver placeholders for a quick local boot — for PostgreSQL + Redis + M-Pesa/SMS you must add the variables listed under [Environment Variables](#environment-variables) below.

### 5. Run migrations and seeders

```bash
php artisan migrate:fresh --seed
```

### 6. Start local server

```bash
php artisan serve
```

API available at `http://127.0.0.1:8000`.

### 7. Start queue worker

Required for SMS/WhatsApp, M-Pesa processing, and provisioning/activation jobs:

```bash
php artisan queue:work
```

---

## Environment Variables

```dotenv
APP_NAME=PrimeBill
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

# PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=primebill
DB_USERNAME=primebill_user
DB_PASSWORD=StrongPass@123

# CORS — comma-separated list of allowed frontend origins
FRONTEND_URL=http://localhost:5173,http://127.0.0.1:5173

QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# M-Pesa Daraja
MPESA_ENV=sandbox
MPESA_CONSUMER_KEY=your_consumer_key
MPESA_CONSUMER_SECRET=your_consumer_secret
MPESA_SHORTCODE=174379
MPESA_PASSKEY=your_passkey
MPESA_CALLBACK_URL=https://yourdomain.com/api/mpesa/stk-callback
MPESA_C2B_VALIDATION_URL=https://yourdomain.com/api/mpesa/c2b-validation
MPESA_C2B_CONFIRMATION_URL=https://yourdomain.com/api/mpesa/c2b-confirmation
# Optional callback hardening
MPESA_CALLBACK_ALLOWED_IPS=
MPESA_CALLBACK_SIGNATURE_SECRET=

# SMS (also reused for WhatsApp via Africa's Talking)
SMS_GATEWAY=africas_talking
SMS_SENDER_ID=PRIMEBILL
AT_API_KEY=your_africas_talking_api_key
AT_USERNAME=sandbox
HOSTPINNACLE_API_KEY=

# Seeding
SEED_ADMIN_PASSWORD=supersecret
SEED_STAFF_PASSWORD=staffsecret
```

> **Never set `FRONTEND_URL=*` in production.** CORS credentials + wildcard origin is rejected by the spec and blocked by all modern browsers. In `local`/`testing` environments the app falls back to `localhost:5173`/`localhost:3000`/`127.0.0.1:5173` automatically if `FRONTEND_URL` is unset.

---

## API Endpoints (summary)

The API has grown to roughly **157 route definitions** across public, portal, and admin/staff groups. Below is a representative summary — see `routes/api.php` for the exhaustive, authoritative list.

### Authentication
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/auth/login` | Login and receive Sanctum token (rate-limited) |
| POST | `/api/auth/password/forgot` | Request password reset |
| POST | `/api/auth/password/reset` | Reset password |
| GET | `/api/auth/me` | Get authenticated user |
| POST | `/api/auth/logout` | Logout |
| POST | `/api/auth/change-password` | Change password |

### Clients & Plans
| Method | Endpoint | Description |
|---|---|---|
| GET/POST | `/api/clients` | List / create clients |
| PUT/DELETE | `/api/clients/{id}` | Update / delete client |
| POST | `/api/clients/{id}/suspend` \| `/activate` | Suspend / activate |
| POST | `/api/clients/{id}/accounts` | Create internet account |
| GET/POST | `/api/plans` | List / create plans |
| POST | `/api/plans/{plan}/assign` | Assign plan to client |

### Invoices & Payments
| Method | Endpoint |
|---|---|
| GET/POST | `/api/invoices` |
| POST | `/api/invoices/bulk-generate` |
| GET/POST | `/api/payments` |
| GET | `/api/payments/summary` |
| GET | `/api/payments/{payment}/receipt` |
| POST | `/api/mpesa/stk-push` |

### M-Pesa Callbacks (no auth, IP/signature verified)
| Method | Endpoint |
|---|---|
| POST | `/api/mpesa/stk-callback` |
| POST | `/api/mpesa/c2b-validation` |
| POST | `/api/mpesa/c2b-confirmation` |

### Vouchers, Loyalty & Referrals
| Method | Endpoint |
|---|---|
| GET | `/api/vouchers/stats`, `/api/vouchers/batches` |
| GET/POST | `/api/vouchers`, `/api/vouchers/generate` |
| GET | `/api/loyalty/leaderboard`, `/api/loyalty/transactions`, `/api/loyalty/points/{clientId}` |
| POST | `/api/loyalty/redeem` |
| GET/POST | `/api/referral/code`, `/api/referral/join`, `/api/referral/stats` |

### FUP, RADIUS & Routers
| Method | Endpoint |
|---|---|
| GET | `/api/fup/stats`, `/api/fup/logs`, `/api/fup/status/{account_id}` |
| POST | `/api/fup/reset/{account_id}` |
| GET/POST | `/api/radius/sessions`, `/api/radius/sync` |
| POST | `/api/webhooks/radius/accounting` (no auth) |
| GET/POST | `/api/routers`, `/api/routers/{router}/test-connection` |

### Tickets, SMS & Admin
| Method | Endpoint |
|---|---|
| GET/POST | `/api/tickets`, `/api/tickets/{ticket}/reply`, `/assign`, `/close`, `/escalate` |
| POST | `/api/sms/send`, `/api/sms/send-bulk` |
| GET/POST | `/api/admin/users`, `/api/admin/roles` |

### Dashboard, Analytics & Reports
| Method | Endpoint |
|---|---|
| GET | `/api/dashboard/stats`, `/traffic`, `/top-downloaders` |
| GET | `/api/analytics/income`, `/api/analytics/summary` |
| GET | `/api/reports/{income,clients,invoices,sms,network,inventory,expenditure}` |
| GET | `/api/reports/{type}/export` |

### Client Portal & Captive Portal
| Method | Endpoint |
|---|---|
| POST | `/api/portal/register`, `/api/portal/login` |
| GET | `/api/portal/dashboard`, `/invoices`, `/payments`, `/balance`, `/profile` |
| POST | `/api/portal/payments/stk-push` |
| GET | `/api/portal/captive/plans`, `/api/portal/captive/status/{username}` (public, throttled) |
| POST | `/api/portal/captive/pay`, `/api/portal/captive/redeem` (public, throttled) |

For request/response shapes, inspect `routes/api.php` and the corresponding controllers in `app/Http/Controllers`.

---

## Scheduled Jobs & Queue

Scheduled commands are defined in `routes/console.php`:

| Command | Schedule | Description |
|---|---|---|
| `billing:generate-invoices` | Monthly, 1st @ 08:00 | Generate subscriber invoices |
| `billing:suspend-overdue` | Daily @ 09:00 | Suspend overdue accounts |
| `billing:send-reminders` | Daily @ 08:00 | Send invoice reminders |
| `payments:reconcile-mpesa` | Hourly | Reconcile M-Pesa transactions (no overlap) |
| `billing:reactivate-paid` | Every 15 min | Reactivate accounts after payment (no overlap) |
| `network:poll-traffic` | Every 5 min | Poll router Tx/Rx traffic (no overlap) |
| `radius:sync-users` | Daily @ 02:00 | Sync RADIUS users (no overlap) |
| `logs:clean` | Weekly | Clean old system logs |

Add the Laravel scheduler to cron on production:

```bash
* * * * * cd /var/www/primebill-api && php artisan schedule:run >> /dev/null 2>&1
```

Example Supervisor config for queue workers:

```ini
[program:primebill-worker]
command=php /var/www/primebill-api/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=2
stdout_logfile=/var/www/primebill-api/storage/logs/worker.log
```

---

## Testing

```bash
php artisan test
```

Feature coverage currently includes: `ClientApiTest`, `MpesaCallbackTest`, `PortalRegistrationTest`, `ProvisioningTest`, `PasswordResetTest`, and `InvoiceTaxTest`, plus the default example tests. Pest is available as a dev dependency alongside PHPUnit.

---

## Known Issues / Tech Debt

These were found while auditing the codebase for this README update — worth triaging before the next deploy:

- **`WhatsAppService` config mismatch:** it reads `config('services.africastalking.api_key')`, but `config/services.php` has no `africastalking` entry — the Africa's Talking key is only registered under `config('sms.africas_talking.api_key')`. As written, WhatsApp sends will always resolve a null API key.
- **Duplicate `EmailService` classes:** one in `App\Services\Communication\EmailService` (used by the notification flow, sends raw HTML via `Mail::html`) and one in `App\Services\Email\EmailService` (uses `Mail::send` with a view). Worth consolidating to avoid drift.
- **`LoyaltyTransaction` model has no backing migration:** `app/Models/LoyaltyTransaction.php` exists but no migration creates a `loyalty_transactions` table — only `loyalty_points` is migrated. Confirm whether this model is still needed before wiring it up.
- **No PDF generation dependency:** invoice/receipt PDF export mentioned in earlier planning docs (`MERGE_CHANGELOG.md`) isn't installed or implemented — `payments/{payment}/receipt` currently returns JSON, not a file.
- Several planning docs in the repo root (`MERGE_CHANGELOG.md`, `PROJECT_AUDIT.md`, `MVP_GAP_ANALYSIS.md`, `SPRINT_IMPLEMENTATION_GUIDE.md`) describe proposed or in-progress work (e.g. a `Subscription` model/table, `mpdf`, Sentry, `spatie/laravel-activitylog`) that isn't present in the current `app/` or `composer.json` — treat them as historical planning artifacts rather than a description of shipped code.

---

## Default Credentials

Set these in `.env` before seeding. **Change after first login.**

| Variable | Default user | Email |
|---|---|---|
| `SEED_ADMIN_PASSWORD` | Super Admin | admin@primebill.co.ke |
| `SEED_STAFF_PASSWORD` | Staff | staff@primebill.co.ke |

---

## Running in Production (deployment notes)

### Server (Nginx + PHP-FPM)

```bash
cd /var/www
git clone https://github.com/Onesmuschege/primebill-api.git
cd primebill-api
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed
php artisan optimize
```

Example Nginx config:

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/primebill-api/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

Enable HTTPS via Certbot and restart Nginx. Configure Supervisor for queue workers.

### Railway Deployment

1. Add a **PostgreSQL plugin** to your Railway project (one click).
2. Railway provides a `DATABASE_URL` — set it in your environment variables:

```dotenv
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://user:pass@host:5432/railway
FRONTEND_URL=https://app.primebill.co.ke,https://primebill-frontend.vercel.app
```

3. Run migrations on deploy:

```bash
php artisan migrate --force
php artisan optimize
```

---

## Contributing & Support

This repository is maintained by the PrimeBill team. For feature requests, bug reports, or support please open an issue or contact the maintainer.

If you'd like to contribute code, open a PR with a clear description and tests where applicable. Follow PSR-12 code style and include migration/seed updates if adding new models.

---

## Related Repositories

- **Frontend:** https://github.com/Onesmuschege/primebill-frontend
- **Historical/other backend:** https://github.com/Onesmuschege/primebill

---

## License

Proprietary — All rights reserved. For licensing or commercial use contact the author.

---

## Author

**Onesmus Chege** — https://github.com/Onesmuschege

---

_PrimeBill API — Backend for PrimeBill ISP Billing System — Powered by DarkOpsHub_
