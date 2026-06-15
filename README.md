# PrimeBill API

> A robust, production-ready ISP Billing & Network Management REST API built with Laravel 11, designed specifically for Kenyan Internet Service Providers.

![Laravel](https://img.shields.io/badge/Laravel-11.x-red) ![PHP](https://img.shields.io/badge/PHP-8.3-blue) ![MySQL](https://img.shields.io/badge/MySQL-8.x-orange) ![License](https://img.shields.io/badge/License-Proprietary-red) ![Status](https://img.shields.io/badge/Status-Active-brightgreen)

---

## Overview

PrimeBill API is the backend engine powering the PrimeBill ISP Billing System. It provides a comprehensive REST API covering subscriber management, automated billing, M-Pesa Daraja payment processing, MikroTik RouterOS integration, FreeRADIUS synchronization, SMS notifications, and real-time network monitoring all tailored for the Kenyan ISP market.

---

## Features

- **Authentication** — Laravel Sanctum token-based auth with role & permission management (Spatie)
- **Client Management** — Full subscriber lifecycle with account suspension/activation
- **Plans & Services** — PPPoE, Hotspot, and Static IP plans with FUP and burst speed support
- **Invoicing Engine** — Auto-numbered invoices (INV-YYYY-XXXXXX), bulk generation, PDF export via DomPDF
- **Payments** — Cash, bank transfer, and M-Pesa recording with automatic invoice reconciliation
- **M-Pesa Daraja** — STK Push, C2B Paybill, transaction status, sandbox & production support
- **SMS Notifications** — Africa's Talking and Hostpinnacle gateways with queued delivery
- **MikroTik Integration** — RouterOS API for PPPoE/Hotspot user management and traffic polling
- **FreeRADIUS Sync** — RADIUS user synchronization and accounting data ingestion
- **FUP Engine** — Automated Fair Usage Policy enforcement with MikroTik profile switching
- **Ticketing System** — Support ticket lifecycle with threaded replies and escalation
- **Dashboard & Analytics** — Real-time KPIs, network traffic graphs, top downloaders
- **Finance Module** — Expenditure tracking, sales commissions, net revenue reporting
- **Inventory Management** — Equipment tracking with client assignment and low-stock alerts
- **System Settings** — Key-value settings store with Redis caching
- **Audit Logs** — Full system audit trail with old/new value diffing
- **Scheduled Jobs** — Auto invoice generation, overdue suspension, SMS reminders, log cleanup
- **Client Portal API** — Separate authenticated endpoints for client self-service

---

## Tech Stack

| Technology | Purpose |
|---|---|
| **Laravel 11** | Core framework |
| **PHP 8.3** | Runtime |
| **MySQL 8** | Primary database |
| **Redis** | Queue driver & settings cache |
| **Laravel Sanctum** | API token authentication |
| **Spatie Permission** | Role & permission management |
| **Laravel Queues** | Async SMS, PDF generation, M-Pesa processing |
| **Laravel Scheduler** | Automated billing jobs |
| **DomPDF** | Invoice PDF generation |
| **RouterOS API** | MikroTik integration |
| **Safaricom Daraja** | M-Pesa STK Push & C2B |
| **Africa's Talking** | SMS gateway |

---

## Project Structure

```
primebill/
├── app/
│   ├── Console/Commands/           # Scheduled artisan commands
│   │   ├── GenerateMonthlyInvoices.php
│   │   ├── SuspendOverdueAccounts.php
│   │   ├── SendInvoiceReminders.php
│   │   ├── PollRouterTraffic.php
│   │   ├── SyncRadiusUsers.php
│   │   └── CleanOldLogs.php
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/                # Admin/Staff API controllers
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── ClientController.php
│   │   │   │   ├── ClientAccountController.php
│   │   │   │   ├── PlanController.php
│   │   │   │   ├── RouterController.php
│   │   │   │   ├── InvoiceController.php
│   │   │   │   ├── PaymentController.php
│   │   │   │   ├── MpesaController.php
│   │   │   │   ├── SmsController.php
│   │   │   │   ├── TicketController.php
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── ExpenditureController.php
│   │   │   │   ├── CommissionController.php
│   │   │   │   ├── InventoryController.php
│   │   │   │   ├── SettingsController.php
│   │   │   │   ├── LogController.php
│   │   │   │   └── ReportController.php
│   │   │   └── Portal/             # Client self-service controllers
│   │   │       ├── PortalAuthController.php
│   │   │       ├── PortalDashboardController.php
│   │   │       ├── PortalInvoiceController.php
│   │   │       ├── PortalPaymentController.php
│   │   │       ├── PortalTicketController.php
│   │   │       └── PortalProfileController.php
│   │   └── Requests/               # Form validation classes
│   │
│   ├── Jobs/                       # Queued jobs
│   │   ├── SendSmsJob.php
│   │   ├── SendBulkSmsJob.php
│   │   └── ProcessMpesaPayment.php
│   │
│   ├── Models/                     # Eloquent models
│   │   ├── User.php
│   │   ├── Client.php
│   │   ├── ClientAccount.php
│   │   ├── Plan.php
│   │   ├── Router.php
│   │   ├── Invoice.php
│   │   ├── Payment.php
│   │   ├── Ticket.php
│   │   ├── TicketReply.php
│   │   ├── SmsLog.php
│   │   ├── Expenditure.php
│   │   ├── InventoryItem.php
│   │   ├── NetworkTraffic.php
│   │   ├── RadiusSession.php
│   │   ├── SalesCommission.php
│   │   ├── FupLog.php
│   │   ├── SystemLog.php
│   │   ├── Setting.php
│   │   └── Notification.php
│   │
│   └── Services/                   # Business logic layer
│       ├── Auth/AuthService.php
│       ├── Billing/
│       │   ├── InvoiceService.php
│       │   └── PaymentService.php
│       ├── Client/ClientService.php
│       ├── Dashboard/DashboardService.php
│       ├── Finance/
│       │   ├── ExpenditureService.php
│       │   └── CommissionService.php
│       ├── Inventory/InventoryService.php
│       ├── Mpesa/MpesaService.php
│       ├── Network/
│       │   ├── MikroTikService.php
│       │   └── RouterService.php
│       ├── Reporting/ReportService.php
│       ├── Settings/SettingsService.php
│       └── Sms/
│           ├── SmsService.php
│           └── Gateways/
│               ├── SmsGatewayInterface.php
│               ├── AfricasTalkingGateway.php
│               └── HostpinnacleGateway.php
│
├── config/
│   ├── mpesa.php                   # M-Pesa configuration
│   └── sms.php                     # SMS gateway configuration
│
├── database/
│   ├── migrations/                 # 18 database migrations
│   └── seeders/
│       ├── RolesAndPermissionsSeeder.php
│       ├── AdminUserSeeder.php
│       ├── SettingsSeeder.php
│       └── PlanSeeder.php
│
└── routes/
    ├── api.php                     # All API routes
    └── console.php                 # Scheduled job definitions
```

---

## Database Schema

| Table | Description |
|---|---|
| `users` | Admin and staff accounts |
| `clients` | ISP subscriber profiles |
| `client_accounts` | PPPoE/Hotspot credentials per client |
| `plans` | Internet service plans |
| `routers` | MikroTik router configurations |
| `invoices` | Generated billing invoices |
| `payments` | Recorded payments (M-Pesa, cash, bank) |
| `tickets` | Support tickets |
| `ticket_replies` | Threaded ticket replies |
| `sms_logs` | SMS delivery logs |
| `expenditures` | Company expense records |
| `inventory_items` | Equipment inventory |
| `network_traffic` | Router Tx/Rx polling data |
| `radius_sessions` | RADIUS accounting sessions |
| `sales_commissions` | Staff commission records |
| `fup_logs` | FUP trigger and reset events |
| `system_logs` | Full audit trail |
| `settings` | Key-value system configuration |
| `notifications` | In-app notifications |

---

## Prerequisites

- PHP 8.3+
- MySQL 8.x
- Redis
- Composer 2.x

---

## Getting Started

### 1. Clone the repository

```bash
git clone https://github.com/Onesmuschege/primebill.git
cd primebill
```

### 2. Install dependencies

```bash
composer install
```

### 3. Environment setup

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configure .env

```env
APP_NAME=PrimeBill
APP_ENV=local
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
```

### 5. Database setup

```bash
php artisan migrate --seed
```

### 6. Start the development server

```bash
php artisan serve
```

API available at `http://127.0.0.1:8000`

### 7. Start the queue worker

```bash
php artisan queue:work
```

---

## Default Credentials

> **Change these immediately after first login.** Set `SEED_ADMIN_PASSWORD` and `SEED_STAFF_PASSWORD` in `.env` before running `php artisan db:seed`.

| Role | Email | Default (if env not set) |
|---|---|---|
| Super Admin | admin@primebill.co.ke | Set via `SEED_ADMIN_PASSWORD` |
| Staff | staff@primebill.co.ke | Set via `SEED_STAFF_PASSWORD` |

---

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/auth/login` | Login and get token |
| POST | `/api/auth/password/forgot` | Request password reset link |
| POST | `/api/auth/password/reset` | Reset password with token |
| GET | `/api/auth/me` | Get authenticated user |
| POST | `/api/auth/logout` | Logout |
| POST | `/api/auth/change-password` | Change password |

### Clients
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/clients` | List all clients |
| POST | `/api/clients` | Create client |
| GET | `/api/clients/{id}` | Get client details |
| PUT | `/api/clients/{id}` | Update client |
| DELETE | `/api/clients/{id}` | Delete client |
| POST | `/api/clients/{id}/suspend` | Suspend client |
| POST | `/api/clients/{id}/activate` | Activate client |
| POST | `/api/clients/{id}/accounts` | Create internet account |

### Invoices
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/invoices` | List invoices |
| POST | `/api/invoices` | Create invoice |
| POST | `/api/invoices/bulk-generate` | Bulk generate invoices |
| PUT | `/api/invoices/{id}` | Update invoice |
| DELETE | `/api/invoices/{id}` | Delete invoice |

### Payments
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/payments` | List payments |
| POST | `/api/payments` | Record payment |
| GET | `/api/payments/summary` | Daily summary |
| POST | `/api/mpesa/stk-push` | Initiate M-Pesa STK Push |

### M-Pesa Callbacks (No Auth Required)
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/mpesa/stk-callback` | STK Push result |
| POST | `/api/mpesa/c2b-validation` | C2B validation |
| POST | `/api/mpesa/c2b-confirmation` | C2B confirmation |

### Dashboard
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/dashboard/stats` | All KPI statistics |
| GET | `/api/dashboard/traffic` | Network traffic data |
| GET | `/api/dashboard/top-downloaders` | Top bandwidth users |
| GET | `/api/analytics/income` | Income analytics |

### Reports
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/reports/income` | Income report |
| GET | `/api/reports/clients` | Client report |
| GET | `/api/reports/invoices` | Invoice report |
| GET | `/api/reports/sms` | SMS report |
| GET | `/api/reports/network` | Network usage report |
| GET | `/api/reports/inventory` | Inventory report |
| GET | `/api/reports/{type}/export` | Export report as CSV |

### Client Portal
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/portal/login` | Client login |
| GET | `/api/portal/dashboard` | Account overview |
| GET | `/api/portal/invoices` | Client invoices |
| POST | `/api/portal/payments/stk-push` | Self-pay via M-Pesa |
| GET | `/api/portal/tickets` | Client tickets |
| POST | `/api/portal/tickets` | Submit new ticket |

---

## Roles & Permissions

| Role | Access Level |
|---|---|
| `super_admin` | Full access to all modules and settings |
| `admin` | All modules except system-level settings |
| `staff` | Clients, billing, tickets, SMS |
| `client` | Self-service portal only |

---

## Scheduled Jobs

Defined in `routes/console.php`:

| Command | Schedule | Description |
|---|---|---|
| `billing:generate-invoices` | 1st of month, 8AM | Auto-generate monthly invoices |
| `billing:suspend-overdue` | Daily, 9AM | Suspend accounts 3+ days overdue |
| `billing:send-reminders` | Daily, 8AM | SMS reminders for upcoming due invoices |
| `logs:clean` | Weekly | Delete logs older than 90 days |

Enable on server:
```bash
* * * * * cd /var/www/primebill && php artisan schedule:run >> /dev/null 2>&1
```

---

## Queue Worker (Production)

```ini
[program:primebill-worker]
command=php /var/www/primebill/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=2
stdout_logfile=/var/www/primebill/storage/logs/worker.log
```

---

## M-Pesa Setup

### Sandbox
1. Sign up at [developer.safaricom.co.ke](https://developer.safaricom.co.ke)
2. Create sandbox app and copy credentials to `.env`
3. Use [ngrok](https://ngrok.com) to expose localhost for callbacks

### Production
```env
MPESA_ENV=live
MPESA_CONSUMER_KEY=live_key
MPESA_CONSUMER_SECRET=live_secret
MPESA_SHORTCODE=your_paybill
MPESA_PASSKEY=live_passkey
```

---

## Production Deployment

```bash
# Clone & install
cd /var/www
git clone https://github.com/Onesmuschege/primebill.git
cd primebill
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed
php artisan optimize

# Nginx
sudo nano /etc/nginx/sites-available/primebill-api
```

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/primebill/public;
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

```bash
sudo ln -s /etc/nginx/sites-available/primebill-api /etc/nginx/sites-enabled/
sudo certbot --nginx -d api.yourdomain.com
sudo systemctl restart nginx
```

---

## Related Repositories

- **Frontend:** [github.com/Onesmuschege/primebill-frontend](https://github.com/Onesmuschege/primebill-frontend)
- **Backend API:** [github.com/Onesmuschege/primebill](https://github.com/Onesmuschege/primebill)

---

## License

Proprietary — All rights reserved.

---

## Author

**Onesmus Chege**

---

*PrimeBill API v1.0 — Powered by Laravel 11*
