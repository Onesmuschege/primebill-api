# PrimeBill API

> PrimeBill API is the backend engine powering the PrimeBill ISP Billing System. It provides a comprehensive REST API covering subscriber management, automated billing, M-Pesa Daraja payment processing, MikroTik RouterOS integration, FreeRADIUS synchronization, SMS notifications, and real-time network monitoring tailored for the Kenyan ISP market.

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
- [Default Credentials](#default-credentials)
- [Contributing & Support](#contributing--support)
- [Related Repositories](#related-repositories)
- [License](#license)
- [Author](#author)

---

## Overview

PrimeBill API is a production-ready Laravel 12 application that implements billing and network management features commonly required by ISPs in Kenya. It provides an admin/staff REST API and a client portal API for subscriber self-service. Core responsibilities include subscriber management, automated billing and invoicing, M-Pesa integration (STK Push, C2B), MikroTik RouterOS management, FreeRADIUS synchronization, SMS notifications, inventory & finance tracking, and real-time network analytics.

> **Database:** As of June 2026, PrimeBill has fully migrated from MySQL to **PostgreSQL 18** for improved multi-tenant isolation, exact numeric precision on financial transactions, superior JSONB support for M-Pesa callback payloads, and first-class Railway deployment compatibility.

---

## Key Features

- **Authentication:** Laravel Sanctum token-based authentication with role & permission management (Spatie Permissions).
- **Subscriber lifecycle:** Create/update/delete clients, suspend/activate accounts, manage PPPoE/Hotspot credentials.
- **Plans & services:** Support for PPPoE, Hotspot and static-IP plans, FUP (fair usage) and burst speeds.
- **Invoicing engine:** Auto-numbered invoices, bulk-generation, PDF export (DomPDF).
- **Payments:** Record cash/bank/M-Pesa payments with automatic invoice reconciliation and idempotency protection.
- **M-Pesa Daraja:** STK Push initiation, C2B validation/confirmation, race-condition-safe callback handling, sandbox & production modes.
- **SMS notifications:** Pluggable gateways (Africa's Talking, Hostpinnacle) with queued delivery.
- **MikroTik integration:** RouterOS API for user provisioning, profile switching, and traffic polling.
- **FreeRADIUS sync:** Sync RADIUS users and ingest accounting sessions.
- **FUP engine:** Automated enforcement with MikroTik profile switching and FUP logging.
- **Ticketing:** Support ticket lifecycle with threaded replies and escalation paths.
- **Dashboard & analytics:** KPIs, traffic graphs, top bandwidth users, income analytics.
- **Finance & inventory:** Expense tracking, sales commissions, inventory with low-stock alerts.
- **Audit logs & settings:** Full audit trail and a key-value settings store with Redis caching.
- **Scheduled jobs and queues:** Automated invoice generation, reminders, overdue suspension, and background M-Pesa/SMS processing.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP 8.3) |
| Database | **PostgreSQL 18** |
| Cache & Queue | Redis |
| API Auth | Laravel Sanctum |
| RBAC | Spatie Permissions |
| PDF | DomPDF |
| Network | RouterOS API (MikroTik) |
| Payments | Safaricom Daraja (M-Pesa) |
| SMS | Africa's Talking / Hostpinnacle |
| Package Manager | Composer 2.x |

---

## Project Structure (high-level)

| Directory | Purpose |
|---|---|
| `app/Console/Commands` | Scheduled artisan commands (invoice generation, suspension, polling, cleanup) |
| `app/Http/Controllers/Api` | Admin/Staff API controllers |
| `app/Http/Controllers/Portal` | Client portal controllers |
| `app/Jobs` | Queued jobs (SMS, M-Pesa processing, PDF generation) |
| `app/Models` | Eloquent models (User, Client, Invoice, Payment, Router, etc.) |
| `app/Services` | Business logic (MpesaService, MikroTikService, InvoiceService, LedgerService) |
| `config/` | Configuration for mpesa, sms, cors, router connections |
| `database/migrations` | 32 schema migrations |
| `database/seeders` | Initial seeders (roles, admin, plans, clients, invoices, payments) |
| `routes/api.php` | All API routes |
| `routes/console.php` | Scheduled commands |

---

## Database Schema (summary)

| Table | Description |
|---|---|
| `users` | Admin/staff accounts |
| `clients` | Subscriber profiles |
| `client_accounts` | PPPoE/Hotspot credentials per client |
| `plans` | Service plans |
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
| `radius_sessions` | FreeRADIUS accounting sessions |
| `sales_commissions` | Staff commissions |
| `fup_logs` | FUP enforcement events |
| `system_logs` | Full audit trail |
| `settings` | Key-value application settings |
| `notifications` | In-app notifications |

---

## Prerequisites

- PHP 8.3+ with extensions: `pdo_pgsql`, `pgsql`, `sockets`, `zip`
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

Install PostgreSQL 18, then create the database and user:

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

Edit `.env` with your database, Redis, and external service credentials (see [Environment Variables](#environment-variables) below).

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

Required for SMS, M-Pesa processing, and PDF generation:

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
CACHE_DRIVER=redis

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

# SMS
SMS_GATEWAY=africas_talking
SMS_SENDER_ID=PRIMEBILL
AT_API_KEY=your_africas_talking_api_key
AT_USERNAME=sandbox

# Seeding
SEED_ADMIN_PASSWORD=supersecret
SEED_STAFF_PASSWORD=staffsecret
```

> **Never set `FRONTEND_URL=*` in production.** CORS credentials + wildcard origin is rejected by the spec and blocked by all modern browsers.

---

## API Endpoints (summary)

### Authentication
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/auth/login` | Login and receive Sanctum token |
| POST | `/api/auth/password/forgot` | Request password reset |
| POST | `/api/auth/password/reset` | Reset password |
| GET | `/api/auth/me` | Get authenticated user |
| POST | `/api/auth/logout` | Logout |

### Clients (admin/staff)
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/clients` | List clients |
| POST | `/api/clients` | Create client |
| GET | `/api/clients/{id}` | Client details |
| PUT | `/api/clients/{id}` | Update client |
| POST | `/api/clients/{id}/suspend` | Suspend client |
| POST | `/api/clients/{id}/activate` | Activate client |
| POST | `/api/clients/{id}/accounts` | Create internet account |

### Invoices
| Method | Endpoint |
|---|---|
| GET | `/api/invoices` |
| POST | `/api/invoices` |
| POST | `/api/invoices/bulk-generate` |

### Payments
| Method | Endpoint |
|---|---|
| GET | `/api/payments` |
| POST | `/api/payments` |
| POST | `/api/mpesa/stk-push` |

### M-Pesa Callbacks (no auth)
| Method | Endpoint |
|---|---|
| POST | `/api/mpesa/stk-callback` |
| POST | `/api/mpesa/c2b-validation` |
| POST | `/api/mpesa/c2b-confirmation` |

### Client Portal
| Method | Endpoint |
|---|---|
| POST | `/api/portal/login` |
| GET | `/api/portal/dashboard` |
| GET | `/api/portal/invoices` |
| POST | `/api/portal/payments/stk-push` |

For a complete list of endpoints and request/response shapes, inspect `routes/api.php` and controllers in `app/Http/Controllers`.

---

## Scheduled Jobs & Queue

Scheduled commands are defined in `routes/console.php`:

| Command | Schedule | Description |
|---|---|---|
| `billing:generate-invoices` | Monthly | Generate subscriber invoices |
| `billing:suspend-overdue` | Daily | Suspend overdue accounts |
| `billing:send-reminders` | Daily | Send invoice reminders |
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