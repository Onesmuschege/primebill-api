# PrimeBill API (backend)

This repository is the Laravel 12 backend for PrimeBill — an ISP OSS/BSS platform focused on billing, provisioning (MikroTik + FreeRADIUS), payments (M-Pesa), vouchers, loyalty, and tenant SaaS operations.

This README has been rewritten to reflect the current codebase and commit history (not older planning drafts). For a compact, living project status and next steps see PROJECT_STATUS.md and IMPLEMENTATION_ROADMAP.md in the repo root.

---

## Quick status (current)
- Backend: Laravel 12 (PHP 8.2+). Extensive feature set implemented across billing, provisioning, RADIUS, routers, vouchers, loyalty, tickets, inventory and tenant SaaS foundations.
- Multi-tenancy: tenants table and tenant_id applied across core models; TenantResolver and BelongsToTenant pattern implemented.
- Payments: M-Pesa STK + C2B callbacks with idempotency keys and enhanced callback verification.
- Invoicing: full CRUD, bulk generation, and PDF export via barryvdh/laravel-dompdf (invoice blade template present).
- Network: MikroTik RouterOS provisioning adapter + Mock adapter, FreeRADIUS adapters, router traffic polling jobs.
- Communications: SMS + WhatsApp gateways implemented; consolidated working EmailService wired into billing jobs.
- Tests: feature tests exist for several critical flows (client, MPesa callback, portal registration, provisioning, password reset, invoice tax). Test coverage should be expanded.

---

## What I changed in this README
- Replaced the older, aspirational prose with a concise, accurate snapshot of implemented features and high-priority gaps derived from the code and commit history.
- Pointed to PROJECT_STATUS.md and IMPLEMENTATION_ROADMAP.md for the authoritative, per-module status and the prioritized backlog.

---

## How to get the project running (developer)
1. Copy the example environment and edit `.env` with database, Redis and gateway secrets.

2. Install dependencies and build assets:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

3. Run queues & scheduler in development (example):

```bash
php artisan queue:work redis --sleep=3 --tries=3
php artisan schedule:run
```

4. Run tests:

```bash
php artisan test
```

---

## Key files & locations
- API routes: `routes/api.php`
- Controllers: `app/Http/Controllers/Api` and `app/Http/Controllers/Portal`
- Models: `app/Models`
- Services (domain logic): `app/Services` (organized by domain)
- Jobs: `app/Jobs`
- Console commands (scheduled): `app/Console/Commands`
- Migrations: `database/migrations`
- PDF invoice template: `resources/views/pdf/invoice.blade.php`

---

## Known high-priority gaps (action items)
1. Expand automated tests for billing reconciliation, MPesa idempotency, RADIUS webhook ingestion, router provisioning and tenancy-isolation checks.
2. Add an audit/activity logging integration (e.g. spatie/laravel-activitylog) and wire critical model events.
3. Add CI workflow to run migrations and the full test matrix on pull requests (if not already present or enabled).
4. Implement bulk report/export jobs (queued PDF/CSV/Excel) and storage for large exports.
5. Add production observability (Sentry/Datadog), job/queue monitoring, and alerts for failed scheduled jobs.

---

## Documentation & next steps
- I have created PROJECT_STATUS.md and IMPLEMENTATION_ROADMAP.md as companion documents. These are the canonical, commit-driven plan and backlog — edit them only after code changes to keep them accurate.
- There are several planning documents and historical artifacts in the repo root; I recommend archiving or removing them to avoid confusion. I will present a proposed list of files to archive/remove so you can confirm removal before I delete anything.

---

If you want me to proceed I can:
- Archive/remove outdated planning files (I will list files and justification first), and
- Expand tests and add the CI workflow, and
- Implement the highest-priority fixes in small, reviewable PRs.

Tell me which cleanup actions to perform next (I will not delete files until you confirm).