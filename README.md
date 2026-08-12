# PrimeBill API (backend)

PrimeBill is a multi-tenant ISP OSS/BSS platform built on Laravel 12. This backend handles billing, provisioning (MikroTik + FreeRADIUS), payments (M-Pesa), vouchers, loyalty/referrals, CRM (leads/prospects), field operations, NOC, fiber/OLT management, IPAM, and a full platform-admin console for PrimeBill operators.

---

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

Run the dev stack:

```bash
composer run dev
```

Run the test suite:

```bash
composer test
# or
php artisan test
```

---

## Current feature set

### Multi-tenancy & SaaS
- Tenant registration with slug-based isolation (`/tenants/register`, `/tenants/check-slug`)
- `tenant_id` scoping across core models; `TenantResolver` middleware switches context from route slug to authenticated user
- Platform-admin console (`/api/platform/*`) for cross-tenant operations: tenant CRUD, lifecycle (suspend/activate/archive), quotas, feature flags, impersonation, audit log

### Client Lifecycle & CRM
- Full client CRUD with notes, tags, and custom fields
- Service accounts (PPPoE, Hotspot, Static IP) with status tracking
- Lead management (stats, convert to prospect, mark lost)
- Prospect pipeline (advance stages, mark won/lost, convert to client)

### Subscriptions & Licensing
- Subscription plans with trial, convert, cancel, upgrade, renew
- Subscription payment lifecycle via M-Pesa (initiate, callback, history)
- Usage tracking per subscription

### Billing & Payments
- Invoice CRUD, bulk generation, PDF export (`barryvdh/laravel-dompdf`)
- Payment recording with allocation engine
- M-Pesa STK Push + C2B callbacks with idempotency keys and callback verification
- Expenditure tracking, commissions (approve/pay), and sales commissions

### Network & Provisioning
- MikroTik RouterOS provisioning adapter + Mock adapter
- FreeRADIUS adapters with accounting webhook (`/webhooks/radius/accounting`)
- RADIUS advanced controller (profiles, attributes)
- Router traffic polling jobs and session management
- Network dashboard (unified overview, routers, sessions, events, control logs, RADIUS stats)
- Service network actions (suspend, restore, disconnect, COA)

### NOC & Fiber
- NOC dashboard (overview, devices, metrics, alerts, topology links)
- OLT management (OLTs, PON ports, ONTs)
- Fiber infrastructure (routes, splitters, cabinets, distribution points)
- Incident/outage management (acknowledge, resolve, close, status updates)

### Support & Communications
- Ticketing (Open/Pending/Solved, threaded replies, assignment, escalate, close)
- SMS gateway (Africa's Talking, Hostpinnacle) with single/bulk send, templates, logs, balance
- Communications catalog (campaigns with lifecycle transitions, templates)

### Inventory & Field Ops
- Inventory CRUD with low-stock alerts, assignment, return
- Purchase order workflow (create, approve, receive, complete, cancel)
- Work orders (stats, assign technician, status tracking)

### Security & Compliance
- MFA (TOTP enable/verify/disable, backup codes)
- API keys management
- Login history and security events
- Session management (list, revoke individual, revoke all)

### Client Portal (public + authenticated)
- Tenant-slug-prefixed portal (`/portal/{tenant_slug}`)
- Client self-registration and login
- Captive portal (public plans, theme, status check, M-Pesa pay, voucher redeem)
- Profile management, invoice history, self-payment, ticket submission

### Platform Admin
- Cross-tenant stats, plans, tenant CRUD
- Tenant configuration (company, branding, localization, plan assignment)
- Tenant lifecycle management
- Quotas, limits, feature flags
- Health checks, billing, subscription overview
- Tenant impersonation
- Admin user creation per tenant
- Audit log
- Subscription management (upgrade, suspend, resume, cancel, renew)

---

## Key files & locations

- API routes: `routes/api.php`
- Controllers: `app/Http/Controllers/Api` and `app/Http/Controllers/Portal`
- Models: `app/Models`
- Jobs: `app/Jobs`
- Console commands: `app/Console/Commands`
- Migrations: `database/migrations`
- Seeders: `database/seeders`
- PDF invoice template: `resources/views/pdf/invoice.blade.php`
- Tests: `tests/`

---

## Seeded demo data

After `php artisan migrate --seed`, three demo tenants are created:

| Tenant | Slug |
|--------|------|
| PrimeNet ISP | `primenet-isp` |
| SwiftLink Communications | `swiftlink-communications` |
| MetroWave Internet | `metrowave-internet` |

Each tenant gets 5 staff accounts:

```text
Email:    {slug}.admin@primebill.test
Email:    {slug}.staff@primebill.test
Email:    {slug}.support@primebill.test
Email:    {slug}.technician@primebill.test
Email:    {slug}.finance@primebill.test
Password: Demo@1234  (config default; override via SEED_DEMO_PASSWORD in .env)
```

The **Platform Admin** (`is_platform_admin = true`) is not seeded automatically. Create it manually:

```bash
php artisan platform:make-admin platform@primebill.co.ke
```

Change all demo passwords after first login.

---

## Environment

Key `.env` variables:

| Variable | Purpose |
|----------|---------|
| `SEED_DEMO_PASSWORD` | Password for seeded tenant staff accounts |
| `MPESA_ENV` | `sandbox` or `production` |
| `MPESA_SHORTCODE` | M-Pesa shortcode |
| `MPESA_CONSUMER_KEY` | M-Pesa consumer key |
| `MPESA_CONSUMER_SECRET` | M-Pesa consumer secret |
| `MPESA_PASSKEY` | M-Pesa passkey |
| `SMS_GATEWAY` | `africas_talking` or `hostpinnacle` |
| `RADIUS_DRIVER` | `freeradius` or `mock` |
| `VITE_API_BASE_URL` | Frontend dev proxy target |

---

## Known gaps

1. **Live network integrations require reachable hardware.** The MikroTik/FreeRADIUS/OLT adapters are implemented and wired (with Mock adapters for dev/testing), but pushing plan profiles or provisioning accounts to physical routers requires a live, reachable device with valid credentials (`NETWORK_ROUTER_DRIVER=mikrotik`, `RADIUS_DRIVER=freeradius`). The API returns clear errors when a router is unreachable rather than pretending a sync succeeded.
2. **M-Pesa / SMS require real provider credentials** (`MPESA_CONSUMER_KEY/SECRET`, passkey, shortcode; `SMS_GATEWAY` account keys). The callbacks and idempotency are implemented and unit-tested in sandbox mode, but live traffic needs a registered Daraja app and SMS account.
3. **Billing reconciliation** runs through the ledger/allocations engine, but a dedicated automated monthly-reconciliation report is not yet built.
4. Add production observability (Sentry/Datadog), queue monitoring, and alerts for failed scheduled jobs.
5. Some advanced catalog domains (IPAM, support catalog, communications, customer experience, security admin, field ops, reporting tools) have full backend routes and matching frontend pages, but a few secondary pages (e.g. plan "template" quick-create only) remain thin.

---

## License

Proprietary.
