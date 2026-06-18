# PrimeBill API

> PrimeBill API is the backend engine powering the PrimeBill ISP Billing System. It provides a comprehensive REST API covering subscriber management, automated billing, M-Pesa Daraja payment processing, MikroTik RouterOS integration, FreeRADIUS synchronization, SMS notifications, and real-time network monitoring tailored for the Kenyan ISP market.

![Laravel](https://img.shields.io/badge/Laravel-11.x-red) ![PHP](https://img.shields.io/badge/PHP-8.3-blue) ![MySQL](https://img.shields.io/badge/MySQL-8.x-orange) ![Redis](https://img.shields.io/badge/Redis-compat-brightgreen) ![License](https://img.shields.io/badge/License-Proprietary-lightgrey)

---

Table of Contents
- Overview
- Key Features
- Tech Stack
- Project Structure
- Database Schema
- Prerequisites
- Getting Started
- Environment Variables
- Running in Production
- API Endpoints (summary)
- Scheduled Jobs & Queue
- Default Credentials
- Contributing & Support
- Related Repositories
- License
- Author

---

## Overview

PrimeBill API is a production-ready Laravel 11 application that implements billing and network management features commonly required by ISPs in Kenya. It provides an admin/staff REST API and a client portal API for subscriber self-service. Core responsibilities include subscriber management, automated billing and invoicing, M-Pesa integration (STK Push, C2B), MikroTik RouterOS management, FreeRADIUS synchronization, SMS notifications, inventory & finance tracking, and real-time network analytics.

---

## Key Features
- Authentication: Laravel Sanctum token-based authentication with role & permission management (Spatie Permissions).
- Subscriber lifecycle: Create/update/delete clients, suspend/activate accounts, manage PPPoE/Hotspot credentials.
- Plans & services: Support for PPPoE, Hotspot and static-IP plans, FUP (fair usage) and burst speeds.
- Invoicing engine: Auto-numbered invoices, bulk-generation, PDF export (DomPDF).
- Payments: Record cash/bank/M-Pesa payments with automatic invoice reconciliation.
- M-Pesa Daraja: STK Push initiation, C2B validation/confirmation, sandbox & production modes.
- SMS notifications: Pluggable gateways (Africa's Talking, Hostpinnacle) with queued delivery.
- MikroTik integration: RouterOS API for user provisioning, profile switching, and traffic polling.
- FreeRADIUS sync: Sync RADIUS users and ingest accounting sessions.
- FUP engine: Automated enforcement with MikroTik profile switching and FUP logging.
- Ticketing: Support ticket lifecycle with threaded replies and escalation paths.
- Dashboard & analytics: KPIs, traffic graphs, top bandwidth users, income analytics.
- Finance & inventory: Expense tracking, sales commissions, inventory with low-stock alerts.
- Audit logs & settings: Full audit trail and a key-value settings store with Redis caching.
- Scheduled jobs and queues: Automated invoice generation, reminders, overdue suspension, and background M-Pesa/SMS processing.

---

## Tech Stack
- Laravel 11 (PHP framework)
- PHP 8.3
- MySQL 8.x
- Redis (cache & queue driver)
- Composer 2.x
- Laravel Sanctum (API auth)
- Spatie Permissions (RBAC)
- DomPDF (PDF invoices)
- RouterOS API (MikroTik integration)
- Safaricom Daraja (M-Pesa)
- Africa's Talking / Hostpinnacle (SMS gateways)

---

## Project Structure (high-level)

See the repository for full structure. Key directories:

- app/Console/Commands — scheduled artisan commands (invoice generation, suspension, polling, cleanup)
- app/Http/Controllers/Api — Admin/Staff API controllers
- app/Http/Controllers/Portal — Client portal controllers
- app/Jobs — queued jobs (SMS, M-Pesa processing, PDF generation)
- app/Models — Eloquent models (User, Client, Invoice, Payment, Router, etc.)
- app/Services — Business logic (MpesaService, MikroTikService, Billing/Invoice services)
- config/ — configuration for mpesa, sms, router connections
- database/migrations & seeders — schema migrations and initial seeders
- routes/api.php — all API routes
- routes/console.php — scheduled commands

---

## Database Schema (summary)
Tables include but are not limited to:
- users — admin/staff accounts
- clients — subscriber profiles
- client_accounts — PPPoE/Hotspot credentials per client
- plans — service plans
- routers — MikroTik router configurations
- invoices — billing invoices
- payments — recorded payments (M-Pesa, cash, bank transfers)
- tickets & ticket_replies — support ticketing
- sms_logs — SMS delivery logs
- expenditures — expense records
- inventory_items — equipment inventory
- network_traffic — router Tx/Rx/polled data
- radius_sessions — FreeRADIUS accounting sessions
- sales_commissions — staff commissions
- fup_logs — FUP events
- system_logs — audit trail
- settings — key-value store for application settings
- notifications — in-app notifications

---

## Prerequisites
- PHP 8.3+
- Composer 2.x
- MySQL 8.x (or compatible)
- Redis
- A webserver (Nginx/Apache) and PHP-FPM in production
- Optional: ngrok (for local M-Pesa callback testing)

---

## Getting Started (development)

1. Clone the repository

```bash
git clone https://github.com/Onesmuschege/primebill-api.git
cd primebill-api
```

2. Install PHP dependencies

```bash
composer update
composer install

enable ;extension=sockets
enable ;extension=zip
```

3. Copy and configure environment file

```bash
cp .env.example .env
php artisan key:generate
```

4. Edit `.env` with your database, Redis and external service credentials (see Environment Variables section below).

5. Run migrations and seeders

```bash
php artisan migrate --seed
```

6. Start local server

```bash
php artisan serve
```

API will be available at http://127.0.0.1:8000 by default.

7. Start a queue worker (required for SMS, M-Pesa processing, PDF generation)

```bash
php artisan queue:work
```

---

## Environment Variables (recommended entries)

Important variables (add to `.env`):

APP_NAME=PrimeBill
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=primebill_db
DB_USERNAME=root
DB_PASSWORD=

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

---

## API Endpoints (summary)

Authentication
- POST /api/auth/login — Login and receive token
- POST /api/auth/password/forgot — Request password reset
- POST /api/auth/password/reset — Reset password
- GET /api/auth/me — Get authenticated user
- POST /api/auth/logout — Logout

Clients (admin/staff)
- GET /api/clients — List clients
- POST /api/clients — Create client
- GET /api/clients/{id} — Client details
- PUT /api/clients/{id} — Update client
- POST /api/clients/{id}/suspend — Suspend client
- POST /api/clients/{id}/activate — Activate client
- POST /api/clients/{id}/accounts — Create internet account for client

Invoices
- GET /api/invoices
- POST /api/invoices
- POST /api/invoices/bulk-generate

Payments
- GET /api/payments
- POST /api/payments
- POST /api/mpesa/stk-push — Initiate STK Push

M-Pesa Callbacks (no auth)
- POST /api/mpesa/stk-callback
- POST /api/mpesa/c2b-validation
- POST /api/mpesa/c2b-confirmation

Client Portal
- POST /api/portal/login
- GET /api/portal/dashboard
- GET /api/portal/invoices
- POST /api/portal/payments/stk-push

For a complete list of endpoints and expected request/response shapes, inspect `routes/api.php` and the controllers in `app/Http/Controllers`.

---

## Scheduled Jobs & Queue
Scheduled commands are defined in `routes/console.php`. Common scheduled tasks:
- billing:generate-invoices — generate invoices monthly
- billing:suspend-overdue — suspend overdue accounts daily
- billing:send-reminders — daily invoice reminders
- logs:clean — weekly log cleanup

Add the Laravel scheduler to cron on production:

```bash
* * * * * cd /var/www/primebill-api && php artisan schedule:run >> /dev/null 2>&1
```

Example Supervisor config for queue workers (production):

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
Change defaults after first login. You may set these before seeding:
- SEED_ADMIN_PASSWORD — password for the seeded Super Admin (email: admin@primebill.co.ke)
- SEED_STAFF_PASSWORD — password for the seeded Staff user (email: staff@primebill.co.ke)

---

## Running in Production (deployment notes)
1. Clone and install dependencies on the server

```bash
cd /var/www
git clone https://github.com/Onesmuschege/primebill-api.git
cd primebill-api
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed
php artisan optimize
```

2. Example Nginx site config (adjust paths and PHP-FPM socket)

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

3. Enable HTTPS (Certbot) and restart Nginx. Configure Supervisor to run queue workers.

---

## Contributing & Support
This repository is maintained by the PrimeBill team. For feature requests, bug reports, or support please open an issue or contact the maintainer.

If you'd like to contribute code, open a PR with a clear description and tests where applicable. Follow PSR-12 code style and include migration/seed updates if adding new models.

---

## Related Repositories
- Frontend: https://github.com/Onesmuschege/primebill-frontend
- Historical/other backend: https://github.com/Onesmuschege/primebill

---

## License
Proprietary — All rights reserved. For licensing or commercial use contact the author.

---

## Author
**Onesmus Chege** — https://github.com/Onesmuschege

---

_PrimeBill API — Backend for PrimeBill ISP Billing System_
