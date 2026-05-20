# PrimeBill — Proposed SaaS Multi-Tenant ISP Billing Platform
## System Analysis, Architecture & Design

> **Document Type:** Technical Architecture & System Design Proposal  
> **Scope:** Full redesign of the PrimeBill ISP billing platform into a SaaS, multi-tenant product on a shared database  
> **Date:** May 2026

---

## Table of Contents

1. [Current System Audit Summary](#1-current-system-audit-summary)
2. [Multi-Tenancy Strategy](#2-multi-tenancy-strategy)
3. [Proposed High-Level Architecture](#3-proposed-high-level-architecture)
4. [Database Design & Tenant Isolation](#4-database-design--tenant-isolation)
5. [Core Modules — Existing (Gap Analysis)](#5-core-modules--existing-gap-analysis)
6. [Missing Essential Modules](#6-missing-essential-modules)
7. [API Layer Design](#7-api-layer-design)
8. [Background Processing Architecture](#8-background-processing-architecture)
9. [Network Integration Architecture](#9-network-integration-architecture)
10. [Security Architecture](#10-security-architecture)
11. [Scalability & Performance Design](#11-scalability--performance-design)
12. [Notification & Communication Architecture](#12-notification--communication-architecture)
13. [Reporting & Analytics Architecture](#13-reporting--analytics-architecture)
14. [Deployment & Infrastructure](#14-deployment--infrastructure)
15. [Implementation Roadmap](#15-implementation-roadmap)

---

## 1. Current System Audit Summary

### 1.1 What Exists (Strengths)

The current `primebill-api` is a Laravel 11 monolith with a reasonable service-layer pattern, covering: client management, invoice generation, M-Pesa payment processing, MikroTik integration, basic RADIUS sync, SMS notifications, support ticketing, and a client self-service portal. The frontend is a React 18 SPA with TanStack Query and Zustand.

### 1.2 Critical Deficiencies

The following issues were found through code audit and must be resolved before any SaaS migration:

#### Architecture
| Issue | Severity | Location |
|-------|----------|----------|
| `SubscriptionService` references non-existent `Subscription` model — entire service is non-functional | CRITICAL | `app/Services/Billing/SubscriptionService.php` |
| `PollRouterTraffic` command has an empty `handle()` method — stub never implemented | CRITICAL | `app/Console/Commands/PollRouterTraffic.php` |
| No tenant isolation anywhere — no `tenant_id`, no global scopes, no middleware | CRITICAL | All models |
| `StorePaymentRequest::authorize()` returns `true` unconditionally — any user can record payments | HIGH | `app/Http/Requests/Payment/StorePaymentRequest.php` |
| Fat controllers bypass service layer (TicketController, ExpenditureController) | MEDIUM | Multiple controllers |
| Direct `Setting::where('key', ...)` queries scattered everywhere instead of SettingsService | MEDIUM | Multiple commands |

#### Performance
| Issue | Severity | Location |
|-------|----------|----------|
| N+1 queries in `InvoiceService::bulkGenerate()` — no eager loading in loop | HIGH | `app/Services/Billing/InvoiceService.php:160` |
| N+1 in `DashboardService::getTopDownloaders()` — `account->client` loaded per row | HIGH | `app/Services/Dashboard/DashboardService.php:157` |
| `ReportService::getIncomeReport()` loads all payment records into PHP memory | HIGH | `app/Services/Reporting/ReportService.php:18` |
| Dashboard stats recalculate on every HTTP request — no caching | HIGH | `app/Http/Controllers/Api/DashboardController.php:19` |
| Missing composite indexes on `invoices`, `payments`, `client_accounts` tables | HIGH | Database migrations |
| Orphaned migration references non-existent tables (`mpesa_callbacks`, `payment_failures`) | MEDIUM | `database/migrations/2026_04_25_134600` |

#### Security
| Issue | Severity | Location |
|-------|----------|----------|
| M-Pesa callback HMAC validation is optional — disabled if `MPESA_SECRET` not set | HIGH | `app/Http/Middleware/ValidateMpesaCallback.php:23` |
| No rate limiting on export endpoints — bulk data exfiltration risk | HIGH | `routes/api.php:228` |
| Race condition in M-Pesa STK callback processing — no DB-level locking | HIGH | `app/Services/Mpesa/MpesaService.php:100` |
| Passwords validated to minimum 6 characters | MEDIUM | `app/Http/Controllers/Api/ClientAccountController.php:30` |
| `SendSmsJob` missing timeout — could hang indefinitely | MEDIUM | `app/Jobs/SendSmsJob.php` |

#### Incomplete Features
- **No IP Address Management (IPAM)** — static IPs assigned with no pool tracking
- **No FUP (Fair Usage Policy) enforcement engine** — FUP fields exist on plans but logic is not implemented
- **No dunning workflow** — overdue suspension is binary; no grace periods or escalating notices
- **No hotspot/voucher system** — PPPoE supported but hotspot use case missing
- **No commission/agent management** — referenced in `Finance` module but no model
- **No white-labeling** — single-tenant branding only
- **No webhook system** — no event dispatch to tenant-configured URLs
- **No KYC/document management** — client onboarding has no ID verification
- **No SLA tracking** — no uptime or service quality metrics per client

---

## 2. Multi-Tenancy Strategy

### 2.1 Chosen Model: Shared Database, Shared Schema (Row-Level Isolation)

For a SaaS ISP billing platform targeting small-to-medium ISPs, the optimal model is a **single shared database** with `tenant_id` as a discriminator column on every tenant-scoped table. This provides:

- Low operational overhead (one database cluster to maintain)
- Cost efficiency at scale (no per-tenant DB provisioning)
- Simple cross-tenant analytics for the platform operator
- Acceptable isolation for SMB ISP customers

Trade-off: Stronger isolation (separate schemas or databases) is not chosen because the target customer is SMB ISPs where shared DB overhead risk is low and data volumes are manageable.

### 2.2 Tenant Hierarchy

```
Platform (SaaS Operator)
└── Tenant (ISP company) ─ has subdomain, branding, settings
    ├── Users (staff, admins per ISP)
    ├── Clients (subscribers of that ISP)
    ├── Plans, Routers, Invoices, Payments, etc.
    └── Tenant Subscription (their SaaS plan with PrimeBill)
```

### 2.3 Tenant Isolation Implementation in Laravel

**Step 1 — Tenant Resolution Middleware**

Every request resolves the current tenant from one of:
- Subdomain: `isp1.primebill.app` → tenant slug `isp1`
- Custom domain: `billing.isp1.co.ke` → domain lookup table
- API key header: `X-Tenant-ID` for machine-to-machine calls

```php
// app/Http/Middleware/ResolveTenant.php
class ResolveTenant {
    public function handle(Request $request, Closure $next) {
        $tenant = Tenant::resolveFromRequest($request);
        if (!$tenant) abort(404);
        app()->instance('tenant', $tenant);
        config(['database.tenant_id' => $tenant->id]);
        return $next($request);
    }
}
```

**Step 2 — Global Scope on All Tenant Models**

```php
// app/Models/Traits/BelongsToTenant.php
trait BelongsToTenant {
    protected static function bootBelongsToTenant(): void {
        static::addGlobalScope('tenant', fn($q) =>
            $q->where(static::getTable() . '.tenant_id', app('tenant')->id)
        );
        static::creating(fn($m) => $m->tenant_id ??= app('tenant')->id);
    }
}
```

**Step 3 — Composite Leading Index**

Every tenant-scoped table index must have `tenant_id` as the leading column:
```sql
INDEX idx_invoices_tenant_status (tenant_id, status, due_date)
INDEX idx_clients_tenant_search  (tenant_id, status, created_at)
```

**Step 4 — Tenant Onboarding Flow**

New ISP signup triggers:
1. `Tenant` record created with `slug`, `plan`, `status`
2. Default admin `User` created and linked
3. Default settings seeded (currency=KES, timezone, SMS gateway)
4. Subdomain DNS record created (if managed DNS)
5. Welcome email dispatched

---

## 3. Proposed High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CLIENT LAYER                                  │
│   Admin SPA (React)  │  Client Portal (React)  │  Mobile App (PWA)  │
└──────────────────────┬──────────────────────────────────────────────┘
                       │ HTTPS
┌──────────────────────▼──────────────────────────────────────────────┐
│                    API GATEWAY / REVERSE PROXY                        │
│   Nginx/Caddy — TLS termination, rate limiting, subdomain routing    │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────────────┐
│                  LARAVEL APPLICATION (Monolith)                       │
│                                                                       │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────────────┐    │
│  │  Admin API  │  │  Portal API  │  │  Webhook / Callback API  │    │
│  └─────────────┘  └──────────────┘  └──────────────────────────┘    │
│                                                                       │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │                    SERVICE LAYER                              │    │
│  │  Billing │ Payment │ Provisioning │ Network │ Notification   │    │
│  │  Dunning │ FUP     │ IPAM        │ Voucher │ Reporting      │    │
│  └──────────────────────────────────────────────────────────────┘    │
│                                                                       │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │                 TENANT CONTEXT MIDDLEWARE                     │    │
│  └──────────────────────────────────────────────────────────────┘    │
└──────────┬──────────────────────┬────────────────────────────────────┘
           │                      │
┌──────────▼──────┐    ┌──────────▼───────────────────────────────┐
│  MySQL 8 (RDS)  │    │       REDIS CLUSTER                       │
│  Shared DB      │    │  Cache │ Queues │ Sessions │ Rate Limits  │
│  tenant_id rows │    └──────────────────────────────────────────┘
└─────────────────┘
           │
┌──────────▼──────────────────────────────────────────────────────────┐
│                    BACKGROUND WORKERS                                 │
│   Queue Worker (Horizon) │ Scheduler │ Event Broadcast Worker        │
└──────────────────────────────────────────────────────────────────────┘
           │
┌──────────▼──────────────────────────────────────────────────────────┐
│               EXTERNAL INTEGRATIONS                                   │
│  M-Pesa Daraja │ Africa's Talking │ Hostpinnacle │ Mailgun/SES       │
│  MikroTik RouterOS API │ FreeRADIUS │ Stripe/Pesapal (SaaS billing)  │
│  Africastalking WhatsApp │ Twilio │ SMPP Gateways                    │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 4. Database Design & Tenant Isolation

### 4.1 Tenant-Level Tables (new)

```sql
-- Core tenant record
CREATE TABLE tenants (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uuid            CHAR(36) UNIQUE NOT NULL,
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(100) UNIQUE NOT NULL,   -- subdomain
    custom_domain   VARCHAR(255) NULL,
    plan            ENUM('starter','growth','enterprise') DEFAULT 'starter',
    status          ENUM('active','suspended','trial','cancelled') DEFAULT 'trial',
    trial_ends_at   TIMESTAMP NULL,
    settings        JSON NOT NULL DEFAULT ('{}'),   -- merged with defaults
    branding        JSON NOT NULL DEFAULT ('{}'),   -- logo, colors, company info
    timezone        VARCHAR(100) DEFAULT 'Africa/Nairobi',
    currency        CHAR(3) DEFAULT 'KES',
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_custom_domain (custom_domain)
);

-- Tenant feature flags / limits
CREATE TABLE tenant_limits (
    tenant_id           BIGINT UNSIGNED PRIMARY KEY,
    max_clients         INT DEFAULT 500,
    max_routers         INT DEFAULT 10,
    max_sms_per_month   INT DEFAULT 5000,
    max_users           INT DEFAULT 5,
    can_white_label     TINYINT(1) DEFAULT 0,
    can_use_api         TINYINT(1) DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Domain → tenant mapping
CREATE TABLE tenant_domains (
    id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    domain      VARCHAR(255) UNIQUE NOT NULL,
    is_primary  TINYINT(1) DEFAULT 0,
    verified_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    INDEX idx_domain (domain)
);
```

### 4.2 Tenant-Scoped Table Pattern

Every tenant-data table follows this pattern:
```sql
CREATE TABLE clients (
    id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,    -- ← isolation column
    uuid        CHAR(36) UNIQUE NOT NULL,
    -- ... business columns ...
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    deleted_at  TIMESTAMP NULL,              -- soft deletes on all tables
    -- Composite leading index always starts with tenant_id
    INDEX idx_tenant_status      (tenant_id, status, created_at),
    INDEX idx_tenant_phone       (tenant_id, phone),
    INDEX idx_tenant_email       (tenant_id, email),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
```

### 4.3 Global/Platform Tables (no tenant_id)

- `tenants`, `tenant_limits`, `tenant_domains`
- `platform_users` (super admins of PrimeBill itself)
- `platform_audit_logs`
- `countries`, `currencies`, `timezones` (reference data)

### 4.4 Complete Entity Relationship Overview

```
tenants (1) ──── (N) users
tenants (1) ──── (N) clients
clients (1) ──── (N) client_accounts
client_accounts (N) ──── (1) plans
plans (1) ──── (N) plan_fup_tiers         ← NEW
clients (1) ──── (N) invoices
invoices (1) ──── (N) invoice_line_items   ← NEW (proration, one-off charges)
invoices (1) ──── (N) payments
payments (N) ──── (1) payment_methods     ← NEW
clients (1) ──── (1) client_wallets       ← NEW (credit balance)
client_wallets (1) ──── (N) wallet_transactions ← NEW
clients (1) ──── (N) dunning_notices      ← NEW
clients (1) ──── (N) subscriptions
subscriptions (N) ──── (1) plans
tenants (1) ──── (N) routers
routers (1) ──── (N) router_interfaces    ← NEW
tenants (1) ──── (N) ip_pools             ← NEW (IPAM)
ip_pools (1) ──── (N) ip_allocations      ← NEW
tenants (1) ──── (N) voucher_batches      ← NEW (hotspot)
voucher_batches (1) ──── (N) vouchers     ← NEW
tenants (1) ──── (N) agents               ← NEW (commission)
agents (1) ──── (N) agent_commissions     ← NEW
clients (1) ──── (N) tickets
tickets (1) ──── (N) ticket_replies
tenants (1) ──── (N) inventory_items
tenants (1) ──── (N) sms_campaigns        ← NEW
tenants (1) ──── (N) webhook_endpoints    ← NEW
tenants (1) ──── (N) audit_logs
```

---

## 5. Core Modules — Existing (Gap Analysis)

### 5.1 Client Management ⚠️ Partial

**Exists:** Basic CRUD, account suspension/activation, phone-number validation.

**Missing:**
- KYC document uploads (ID scan, photo) with status (`pending`, `verified`, `rejected`)
- Client tagging / custom fields per tenant
- Client groups (corporate accounts with multiple sub-accounts)
- Referral tracking (referred_by client_id)
- Client merge / duplicate detection
- Change history per client field (full audit trail with old/new values)

**New Tables Required:**
```sql
client_documents      (id, tenant_id, client_id, type, path, status, verified_by, verified_at)
client_tags           (id, tenant_id, client_id, tag)
client_groups         (id, tenant_id, name, parent_group_id)
client_notes          (id, tenant_id, client_id, user_id, body, is_internal)
```

### 5.2 Billing Engine ⚠️ Partial

**Exists:** Monthly invoice generation, bulk invoice job, basic proration comment in code.

**Missing:**
- True proration calculation when mid-cycle plan change occurs
- Prorated credit notes / adjustments
- One-off charges added to invoice as line items
- Multi-currency invoicing
- Invoice templates per tenant (custom logo, footer, legal text)
- Credit note issuance with auto-application to next invoice
- Advance billing (bill N days before cycle end)
- Invoice numbering sequences per tenant (configurable prefix)
- Tax computation (VAT 16% Kenya, WHT 5% where applicable)
- Recurring charge items beyond plan fee (static IP addon, equipment rental)

**New Tables Required:**
```sql
invoice_line_items    (id, tenant_id, invoice_id, description, quantity, unit_price, tax_rate, total)
credit_notes          (id, tenant_id, client_id, invoice_id, amount, reason, status, applied_at)
tax_rates             (id, tenant_id, name, rate, applies_to, is_default)
invoice_templates     (id, tenant_id, name, header_html, footer_html, is_default)
addon_services        (id, tenant_id, name, price, billing_cycle, category)
client_addons         (id, tenant_id, client_id, addon_id, status, started_at, ended_at)
```

### 5.3 Payment Processing ⚠️ Partial

**Exists:** Manual payment recording, M-Pesa STK push + C2B, basic reconciliation.

**Missing:**
- Pesapal / Flutterwave / Stripe as additional gateways (per-tenant gateway config)
- Payment wallet / credit system (overpayments held as credit)
- Partial payment handling (pay KES 500 against KES 1000 invoice)
- Payment reversal / refund workflow
- Automatic application of wallet credit to new invoices
- Receipt generation and email delivery
- Payment gateway failover (try M-Pesa → Pesapal → manual)
- Chargeback / dispute management

**New Tables Required:**
```sql
payment_gateways      (id, tenant_id, provider, config_json, is_active, is_default)
client_wallets        (id, tenant_id, client_id, balance, currency)
wallet_transactions   (id, tenant_id, wallet_id, type, amount, reference, description, created_at)
payment_refunds       (id, tenant_id, payment_id, amount, reason, status, processed_at)
payment_disputes      (id, tenant_id, payment_id, reason, status, resolved_at)
```

### 5.4 Network Integration ⚠️ Partial

**Exists:** MikroTik RouterOS API for user creation, basic traffic polling stub (empty), FreeRADIUS sync.

**Missing:** See Section 6 for full Network Management module.

### 5.5 SMS & Notifications ⚠️ Partial

**Exists:** Africa's Talking + Hostpinnacle gateway abstraction, single/bulk SMS send.

**Missing:** See Section 12 for full Notification Architecture.

---

## 6. Missing Essential Modules

### 6.1 MODULE: Dunning Management (Collections Workflow)

**What it is:** A configurable, automated escalation workflow that handles overdue accounts through a series of notice stages before suspension, giving clients adequate warnings with grace periods.

**Why it's critical:** Current system suspends accounts on a binary schedule (daily cron). This causes disputes, customer churn, and no opportunity for self-cure. Every professional ISP billing system (Splynx, WHMCS, Powercode) implements dunning.

**Design:**

```sql
dunning_policies (
    id, tenant_id, name, is_default,
    grace_period_days INT,          -- days after due date before step 1
    steps JSON                       -- array of steps with day_offset, action, channel
)

-- Example steps JSON:
-- [
--   {"day_offset": 1,  "action": "notify", "channel": ["sms","email"], "template": "first_reminder"},
--   {"day_offset": 5,  "action": "notify", "channel": ["sms","email"], "template": "second_reminder"},
--   {"day_offset": 10, "action": "restrict_speed", "speed_kbps": 256, "template": "speed_restricted"},
--   {"day_offset": 15, "action": "suspend",  "channel": ["sms","email"], "template": "suspended"},
--   {"day_offset": 30, "action": "terminate","channel": ["sms","email"], "template": "terminated"}
-- ]

dunning_notices (
    id, tenant_id, client_id, invoice_id,
    policy_id, step_index, action, channel,
    status ENUM('pending','sent','failed'),
    scheduled_at, executed_at
)
```

**Dunning Engine Flow:**

```
[Scheduled Job: RunDunningEngine — runs every 6 hours]
    │
    ├── Find all overdue invoices grouped by client
    ├── Load client's dunning policy (or default)
    ├── For each unpaid invoice past due:
    │     ├── Calculate days overdue
    │     ├── Determine current dunning step
    │     ├── If step not yet executed → dispatch DunningStepJob
    │     └── DunningStepJob:
    │           ├── action=notify      → send SMS/email
    │           ├── action=restrict_speed → RADIUS CoA packet to reduce bandwidth
    │           ├── action=suspend     → disable RADIUS user, MikroTik disable
    │           └── action=terminate   → mark account terminated, release IP
    └── Log all actions to dunning_notices
```

### 6.2 MODULE: Fair Usage Policy (FUP) Engine

**What it is:** Automated bandwidth throttling when a subscriber's usage within a billing cycle exceeds defined data thresholds, with automatic restoration at cycle reset.

**Why it's critical:** FUP is a core feature of every ISP plan type in Kenya and Africa. The current codebase has FUP fields on the plans table (`fup_limit_gb`, `fup_speed_kbps`) but zero enforcement logic.

**Design:**

```sql
plan_fup_tiers (
    id, tenant_id, plan_id,
    sequence          INT,           -- order of tiers (1, 2, 3...)
    data_threshold_mb BIGINT,        -- usage threshold to activate this tier
    download_kbps     INT,
    upload_kbps       INT,
    burst_download    INT,
    burst_upload      INT
)
-- Example: 10GB normal speed, 10-20GB half speed, 20GB+ 256kbps

client_usage_cycles (
    id, tenant_id, client_id, client_account_id,
    cycle_start, cycle_end,
    bytes_downloaded  BIGINT DEFAULT 0,
    bytes_uploaded    BIGINT DEFAULT 0,
    current_fup_tier  INT DEFAULT 0,  -- 0 = normal, 1,2,3 = throttled tiers
    last_synced_at    TIMESTAMP
)
```

**FUP Engine Flow:**

```
[Scheduled Job: SyncFupUsage — runs every 15 minutes]
    │
    ├── Poll RADIUS accounting data or MikroTik traffic counters per subscriber
    ├── Update client_usage_cycles bytes_downloaded / bytes_uploaded
    ├── Compare total usage vs plan FUP tiers
    ├── If tier changed:
    │     ├── Send RADIUS Change of Authorization (CoA) packet
    │     │     with new bandwidth limits
    │     ├── Update MikroTik queue for subscriber
    │     ├── Notify client via SMS: "Your speed has been reduced..."
    │     └── Log to fup_actions table
    └── At cycle end: reset usage, restore full speed, notify client
```

### 6.3 MODULE: IP Address Management (IPAM)

**What it is:** A structured system for managing IP address pools, subnets, and individual IP allocations per subscriber.

**Why it's critical:** Without IPAM, IPs are assigned ad-hoc with no central tracking. This leads to duplicate IP assignments, no subnet capacity planning, and no audit trail of who had which IP at a given time.

**Design:**

```sql
ip_pools (
    id, tenant_id, name,
    subnet        VARCHAR(50),     -- e.g. 192.168.10.0/24
    type          ENUM('pppoe','static','hotspot','cgnat'),
    router_id     BIGINT NULL,     -- optionally linked to a router
    gateway       VARCHAR(50),
    dns_primary   VARCHAR(50),
    dns_secondary VARCHAR(50),
    total_ips     INT,
    allocated_ips INT DEFAULT 0,
    is_active     TINYINT(1)
)

ip_allocations (
    id, tenant_id, pool_id,
    ip_address      VARCHAR(50) UNIQUE,
    client_account_id BIGINT NULL,    -- NULL = free
    lease_type      ENUM('static','dynamic'),
    allocated_at    TIMESTAMP NULL,
    released_at     TIMESTAMP NULL,
    mac_address     VARCHAR(20) NULL,
    INDEX idx_pool_free (tenant_id, pool_id, client_account_id)
)

ip_allocation_history (
    id, tenant_id, ip_address,
    client_account_id, allocated_at, released_at
)
```

**IPAM Logic:**
- On new client account: auto-allocate next free IP from pool (or manual)
- On account termination: release IP back to pool
- Subnet utilization reports: capacity planning dashboard
- DHCP lease import: bulk import from router for reconciliation

### 6.4 MODULE: Hotspot & Voucher Management

**What it is:** Management of Wi-Fi hotspot zones, voucher/ticket generation for prepaid internet access, and integration with MikroTik Hotspot profiles.

**Why it's critical:** Many Kenyan ISPs operate hotspot zones (hotels, colleges, public hotspots) alongside PPPoE home connections. This revenue stream is currently unsupported.

**Design:**

```sql
hotspot_zones (
    id, tenant_id, router_id,
    name, profile_name,      -- MikroTik hotspot profile name
    login_url, walled_garden_urls TEXT
)

voucher_batches (
    id, tenant_id, zone_id, plan_id,
    batch_name, quantity, price,
    validity_hours INT,
    created_by, expires_at
)

vouchers (
    id, tenant_id, batch_id,
    code          VARCHAR(50) UNIQUE,
    status        ENUM('unused','active','expired','disabled'),
    activated_at  TIMESTAMP NULL,
    expires_at    TIMESTAMP NULL,
    client_mac    VARCHAR(20) NULL,
    mikrotik_username VARCHAR(100) NULL
)
```

**Voucher Engine:**
- Generate voucher batches (PDF printable booklets)
- MikroTik hotspot user auto-create on first use
- Auto-expire vouchers after validity period
- Cashier/reseller role for voucher sales with commission tracking
- Portal for clients to self-activate vouchers

### 6.5 MODULE: Subscription Management (Complete Rebuild)

**What it is:** Full subscription lifecycle management — the core revenue engine linking clients to plans with billing cycles, auto-renewal, upgrades, downgrades, and cancellations.

**Current State:** `SubscriptionService.php` exists but references a non-existent `Subscription` model. This must be built from scratch.

**Design:**

```sql
subscriptions (
    id, tenant_id, client_id, client_account_id, plan_id,
    status          ENUM('active','pending','suspended','cancelled','expired'),
    billing_cycle   ENUM('monthly','quarterly','annual','custom'),
    cycle_day       TINYINT,        -- day of month billing runs
    started_at      TIMESTAMP,
    current_period_start TIMESTAMP,
    current_period_end   TIMESTAMP,
    next_billing_at      TIMESTAMP,
    cancelled_at         TIMESTAMP NULL,
    cancel_reason        TEXT NULL,
    auto_renew           TINYINT(1) DEFAULT 1,
    trial_ends_at        TIMESTAMP NULL,
    INDEX idx_tenant_renewal (tenant_id, status, next_billing_at)
)

subscription_changes (
    id, tenant_id, subscription_id,
    change_type     ENUM('upgrade','downgrade','plan_change','cycle_change'),
    from_plan_id, to_plan_id,
    effective_date  DATE,
    prorated_credit DECIMAL(12,2),
    recorded_by     BIGINT,
    created_at      TIMESTAMP
)
```

### 6.6 MODULE: Agent & Commission Management

**What it is:** A system for managing sales agents (resellers, field agents) who bring in subscribers, tracking their commissions, and managing payouts.

**Design:**

```sql
agents (
    id, tenant_id,
    user_id       BIGINT NULL,      -- linked user account (if agent logs in)
    name, phone, email,
    code          VARCHAR(20) UNIQUE,  -- referral/agent code
    commission_type  ENUM('percentage','fixed_per_client','monthly_recurring'),
    commission_value DECIMAL(8,2),
    status        ENUM('active','inactive')
)

agent_clients (
    agent_id, client_id, tenant_id, assigned_at
)

agent_commissions (
    id, tenant_id, agent_id, client_id, invoice_id, payment_id,
    type          ENUM('signup','monthly','renewal'),
    amount        DECIMAL(12,2),
    status        ENUM('pending','approved','paid','cancelled'),
    period_start, period_end,
    paid_at       TIMESTAMP NULL
)

agent_payouts (
    id, tenant_id, agent_id,
    amount, method, reference,
    period_start, period_end,
    processed_by, processed_at
)
```

### 6.7 MODULE: Wallet & Credit System

**What it is:** Each client has a credit wallet. Overpayments, credit notes, and promotional credits are held in the wallet and automatically applied to subsequent invoices.

**Design:**

```sql
client_wallets (
    id, tenant_id, client_id,
    balance       DECIMAL(12,2) DEFAULT 0.00,
    currency      CHAR(3) DEFAULT 'KES',
    last_activity TIMESTAMP
)

wallet_transactions (
    id, tenant_id, wallet_id, client_id,
    type          ENUM('credit','debit','refund','adjustment'),
    amount        DECIMAL(12,2),
    running_balance DECIMAL(12,2),
    reference_type VARCHAR(50),  -- 'payment','credit_note','manual'
    reference_id   BIGINT,
    description    TEXT,
    recorded_by    BIGINT NULL,
    created_at     TIMESTAMP,
    INDEX idx_wallet_timeline (tenant_id, wallet_id, created_at)
)
```

**Wallet Logic:**
- On payment received (any amount > invoice total): credit excess to wallet
- On invoice generated: auto-apply wallet credit, generate reduced invoice
- On credit note issued: add credit to wallet
- Wallet balance displayed prominently in client portal

### 6.8 MODULE: Tax Management

**What it is:** Configurable tax rules applied to invoice line items — supporting Kenya VAT (16%), WHT, and custom rates per tenant.

**Design:**

```sql
tax_rates (
    id, tenant_id,
    name          VARCHAR(100),    -- e.g. "VAT 16%", "WHT 5%"
    rate          DECIMAL(5,2),
    type          ENUM('inclusive','exclusive'),
    applies_to    ENUM('all','plans','addons','equipment'),
    is_default    TINYINT(1) DEFAULT 0,
    is_compound   TINYINT(1) DEFAULT 0  -- compound tax calculated on tax
)

invoice_taxes (
    id, tenant_id, invoice_id, tax_rate_id,
    taxable_amount DECIMAL(12,2),
    tax_amount     DECIMAL(12,2)
)
```

### 6.9 MODULE: Webhook & Integration Engine

**What it is:** A system allowing tenant ISPs to subscribe to platform events and receive real-time HTTP callbacks to their own systems (integrating PrimeBill with CRMs, ERP, custom portals).

**Design:**

```sql
webhook_endpoints (
    id, tenant_id,
    url           VARCHAR(500),
    secret        VARCHAR(100),    -- HMAC signing secret
    events        JSON,            -- ["payment.received","client.suspended"]
    is_active     TINYINT(1),
    last_success_at TIMESTAMP NULL,
    last_failure_at TIMESTAMP NULL,
    failure_count   INT DEFAULT 0
)

webhook_deliveries (
    id, tenant_id, endpoint_id, event_type,
    payload       JSON,
    response_status INT NULL,
    response_body   TEXT NULL,
    attempt_count   INT DEFAULT 0,
    status          ENUM('pending','delivered','failed'),
    next_retry_at   TIMESTAMP NULL,
    delivered_at    TIMESTAMP NULL
)
```

**Webhook Events (minimum):**
- `client.created`, `client.updated`, `client.suspended`, `client.activated`
- `invoice.generated`, `invoice.paid`, `invoice.overdue`
- `payment.received`, `payment.reversed`
- `subscription.renewed`, `subscription.cancelled`
- `ticket.created`, `ticket.replied`
- `fup.threshold_reached`, `fup.restored`

### 6.10 MODULE: Platform Tenant Billing (SaaS Metering)

**What it is:** PrimeBill's own billing system for charging ISP tenants for using the platform (subscription + usage-based pricing).

**Design:**

```sql
platform_plans (
    id, name, price_monthly, price_annual,
    max_clients, max_routers, max_sms_month,
    features JSON       -- feature flags
)

tenant_subscriptions (
    id, tenant_id, platform_plan_id,
    status, billing_cycle,
    current_period_start, current_period_end,
    stripe_subscription_id VARCHAR(200) NULL  -- or Pesapal reference
)

platform_usage_logs (
    id, tenant_id, month CHAR(7),  -- '2026-05'
    client_count INT,
    sms_sent INT,
    invoices_generated INT,
    api_calls BIGINT
)

platform_invoices (
    id, tenant_id, amount, status,
    period_start, period_end,
    due_date, paid_at,
    stripe_invoice_id VARCHAR(200) NULL
)
```

### 6.11 MODULE: SLA & Service Quality Tracking

**What it is:** Monitoring and recording of service uptime, incident events, and SLA compliance per client/connection.

**Design:**

```sql
sla_policies (
    id, tenant_id, name,
    uptime_target_pct DECIMAL(5,2),  -- e.g. 99.5
    response_time_hours INT,
    compensation_type ENUM('credit','none')
)

service_incidents (
    id, tenant_id, router_id, client_account_id NULL,
    type          ENUM('outage','degraded','maintenance'),
    started_at, resolved_at,
    description   TEXT,
    affected_clients INT,
    root_cause    TEXT NULL
)

sla_reports (
    id, tenant_id, client_id,
    period_start, period_end,
    uptime_minutes INT,
    downtime_minutes INT,
    incidents_count INT,
    sla_met TINYINT(1)
)
```

### 6.12 MODULE: Audit Trail (Enhanced)

**What it is:** Immutable, comprehensive log of every data change across the system with old/new values, user attribution, and tenant scoping.

**Current State:** `SystemLog` table exists but has no indexes on `created_at` and no structured field-level tracking.

**Enhanced Design:**

```sql
audit_logs (
    id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NULL,         -- NULL for system actions
    user_type     ENUM('admin','staff','client','system','api'),
    event         VARCHAR(100),                 -- 'client.suspended'
    auditable_type VARCHAR(100),                -- 'App\Models\Client'
    auditable_id   BIGINT UNSIGNED,
    old_values     JSON NULL,
    new_values     JSON NULL,
    ip_address     VARCHAR(45) NULL,
    user_agent     VARCHAR(500) NULL,
    url            VARCHAR(500) NULL,
    created_at     TIMESTAMP NOT NULL,
    INDEX idx_tenant_event    (tenant_id, event, created_at),
    INDEX idx_tenant_subject  (tenant_id, auditable_type, auditable_id),
    INDEX idx_tenant_user     (tenant_id, user_id, created_at)
)
```

Use the `OwenIt\Auditing` package or a custom observer on all models.

### 6.13 MODULE: Network Equipment Management (CPE/Device Inventory)

**What it is:** Tracking of Customer Premises Equipment (routers, ONUs, antennas) including assignment to clients, warranty status, and field technician assignment.

```sql
equipment_categories  (id, tenant_id, name, default_warranty_months)
equipment_items (
    id, tenant_id, category_id,
    serial_number, mac_address, model, brand,
    status         ENUM('in_stock','deployed','faulty','retired','lost'),
    client_id      BIGINT NULL,
    assigned_at    TIMESTAMP NULL,
    purchased_at   DATE NULL,
    purchase_price DECIMAL(10,2) NULL,
    warranty_expires DATE NULL,
    location_notes TEXT NULL
)
equipment_movements (
    id, tenant_id, item_id,
    from_status, to_status,
    from_client_id, to_client_id,
    reason, recorded_by, created_at
)
```

---

## 7. API Layer Design

### 7.1 Versioning Strategy

All API routes are versioned: `/api/v1/...`. Breaking changes bump to `/api/v2/...` with deprecation headers on v1.

### 7.2 Route Structure

```
/api/v1/
├── auth/               Public auth routes (throttle: 10/min)
│   ├── POST login
│   ├── POST register (tenant signup)
│   ├── POST forgot-password
│   └── POST reset-password
│
├── portal/             Client self-service (auth:sanctum, role:client)
│   ├── GET  dashboard
│   ├── GET  invoices
│   ├── POST invoices/{id}/pay (initiate M-Pesa)
│   ├── GET  usage             (FUP data usage for current cycle)
│   ├── POST tickets
│   └── GET  tickets/{id}
│
├── admin/              Admin panel (auth:sanctum, role:admin|staff)
│   ├── clients/
│   ├── invoices/
│   ├── payments/
│   ├── plans/
│   ├── subscriptions/
│   ├── routers/
│   ├── ip-pools/           ← NEW (IPAM)
│   ├── vouchers/           ← NEW (Hotspot)
│   ├── agents/             ← NEW
│   ├── dunning/            ← NEW
│   ├── sms/
│   ├── tickets/
│   ├── inventory/
│   ├── reports/
│   ├── finance/
│   ├── settings/
│   └── audit-logs/
│
├── webhooks/           External callbacks (no auth, IP-filtered + HMAC)
│   ├── POST mpesa/stk
│   ├── POST mpesa/c2b/validate
│   ├── POST mpesa/c2b/confirm
│   └── POST radius/accounting
│
└── platform/           Super-admin (auth:sanctum, role:platform_admin)
    ├── tenants/
    ├── tenants/{id}/impersonate
    ├── platform-plans/
    └── analytics/
```

### 7.3 Consistent API Response Envelope

All responses must use a single envelope format:

```json
{
    "success": true,
    "data": { ... },
    "meta": {
        "page": 1,
        "per_page": 25,
        "total": 340,
        "last_page": 14
    },
    "message": null
}
```

Error responses:
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "phone": ["Phone number format is invalid"]
    },
    "code": "VALIDATION_ERROR"
}
```

Standardize via a single `ApiResponse` trait used in **every** controller with no exceptions.

### 7.4 Rate Limiting Strategy

```php
// Per route group, per tenant:
RateLimiter::for('admin-api', fn($req) =>
    Limit::perMinute(120)->by($req->tenant()->id . '|' . $req->user()->id)
);
RateLimiter::for('auth', fn($req) =>
    Limit::perMinute(10)->by($req->ip())
);
RateLimiter::for('exports', fn($req) =>
    Limit::perHour(20)->by($req->tenant()->id)  // protect data export endpoints
);
RateLimiter::for('webhooks', fn($req) =>
    Limit::perMinute(300)->by($req->ip())  // high limit for Safaricom IPs
);
```

---

## 8. Background Processing Architecture

### 8.1 Queue Architecture with Laravel Horizon

Use **Laravel Horizon** for queue visibility and management. Define dedicated queues by priority:

```php
// config/horizon.php queue priorities (high → low)
'queues' => [
    'critical',     // M-Pesa callbacks, payment reconciliation
    'provisioning', // MikroTik/RADIUS user operations
    'billing',      // Invoice generation, dunning steps
    'notifications',// SMS, email dispatch
    'fup',          // FUP sync and CoA sends
    'webhooks',     // Outbound webhook delivery
    'reports',      // Report generation
    'default',      // Everything else
],
```

### 8.2 Complete Job Inventory

| Job Class | Queue | Trigger | Retry |
|-----------|-------|---------|-------|
| `ProcessMpesaCallbackJob` | critical | M-Pesa webhook | 3× immediately |
| `ReconcilePaymentJob` | critical | M-Pesa timeout | 5× with backoff |
| `ProvisionRadiusUserJob` | provisioning | Account activation | 5× with backoff |
| `DeprovisionRadiusUserJob` | provisioning | Suspension | 5× with backoff |
| `UpdateMikrotikBandwidthJob` | provisioning | FUP tier change | 3× |
| `SendCoaPacketJob` | provisioning | FUP/Dunning | 3× |
| `GenerateInvoiceJob` | billing | Billing cycle | 3× |
| `ProcessDunningStepJob` | billing | Dunning cron | 3× |
| `RenewSubscriptionJob` | billing | Renewal date | 3× |
| `SendSmsJob` | notifications | Any SMS trigger | 5× exp backoff |
| `SendEmailJob` | notifications | Any email trigger | 3× |
| `DeliverWebhookJob` | webhooks | Any domain event | 10× exp backoff |
| `GenerateReportJob` | reports | User request | 2× |
| `SyncFupUsageJob` | fup | Cron every 15min | 1× |
| `PollRouterTrafficJob` | fup | Cron every 5min | 1× |
| `GenerateInvoicePdfJob` | default | Post-generation | 3× |

### 8.3 Scheduled Command Inventory (Complete)

```php
// routes/console.php
Schedule::job(GenerateMonthlyInvoicesJob::class)->monthlyOn(1, '08:00')
         ->withoutOverlapping()->onOneServer();

Schedule::job(RunDunningEngineJob::class)->everySixHours()
         ->withoutOverlapping()->onOneServer();

Schedule::job(SyncFupUsageJob::class)->everyFifteenMinutes()
         ->withoutOverlapping()->onOneServer();

Schedule::job(PollRouterTrafficJob::class)->everyFiveMinutes()
         ->withoutOverlapping()->onOneServer();

Schedule::job(ReactivatePaidAccountsJob::class)->everyFifteenMinutes()
         ->withoutOverlapping()->onOneServer();

Schedule::job(ReconcileMpesaPaymentsJob::class)->hourly()
         ->withoutOverlapping()->onOneServer();

Schedule::job(SyncRadiusAccountingJob::class)->everyThirtyMinutes()
         ->withoutOverlapping()->onOneServer();

Schedule::job(ExpireVouchersJob::class)->hourly()
         ->withoutOverlapping()->onOneServer();

Schedule::job(ResetFupCyclesJob::class)->daily()
         ->withoutOverlapping()->onOneServer();

Schedule::job(SendInvoiceRemindersJob::class)->dailyAt('08:00')
         ->withoutOverlapping()->onOneServer();

Schedule::job(GenerateSlaReportsJob::class)->monthlyOn(1, '01:00')
         ->withoutOverlapping()->onOneServer();

Schedule::job(PruneSoftDeletedRecordsJob::class)->monthly()
         ->withoutOverlapping()->onOneServer();

Schedule::job(CleanOldLogsJob::class)->weekly()->withoutOverlapping();
```

All scheduled commands must use `.onOneServer()` to prevent duplicate execution across multiple app server instances.

---

## 9. Network Integration Architecture

### 9.1 Network Abstraction Layer

The current code directly calls `MikroTikService`. Introduce a `NetworkProvisionerInterface` with driver implementations:

```php
interface NetworkProvisionerInterface {
    public function createUser(ClientAccount $account): bool;
    public function disableUser(ClientAccount $account): bool;
    public function enableUser(ClientAccount $account): bool;
    public function updateBandwidth(ClientAccount $account, int $downloadKbps, int $uploadKbps): bool;
    public function sendCoA(ClientAccount $account, array $avps): bool;
    public function getUserSession(ClientAccount $account): ?array;
}

// Drivers:
class MikroTikProvisioner implements NetworkProvisionerInterface { ... }
class RadiusProvisioner implements NetworkProvisionerInterface { ... }
class UbiquitiProvisioner implements NetworkProvisionerInterface { ... }  // future
```

Router records store which driver to use:
```sql
routers.driver ENUM('mikrotik','radius','ubiquiti','none')
```

### 9.2 RADIUS Integration

```sql
radius_servers (
    id, tenant_id,
    host, port, secret,
    type      ENUM('auth','acct','both'),
    is_active TINYINT(1)
)

radius_sessions (
    id, tenant_id, client_account_id,
    username, session_id,
    nas_ip, nas_port_id,
    framed_ip VARCHAR(45),
    bytes_in  BIGINT, bytes_out BIGINT,
    started_at, updated_at, ended_at,
    INDEX idx_tenant_account (tenant_id, client_account_id, started_at)
)

radius_accounting_raw (
    id, tenant_id, session_id,
    payload JSON,
    received_at TIMESTAMP,
    processed   TINYINT(1) DEFAULT 0
)
```

**RADIUS Accounting Endpoint:** `POST /api/webhooks/radius/accounting` — Receives RADIUS Accounting-Stop/Interim packets from FreeRADIUS, stores raw payload, dispatches `ProcessRadiusAccountingJob` for FUP/usage update.

### 9.3 Router Traffic Monitoring (Replacing Empty Stub)

The current `PollRouterTraffic` command is an empty stub. Replace with:

```php
class PollRouterTrafficJob implements ShouldQueue {
    public function handle(MikroTikService $mikrotik): void {
        $routers = Router::where('tenant_id', $this->tenantId)
                         ->where('is_active', true)
                         ->get();
        foreach ($routers as $router) {
            try {
                $interfaces = $mikrotik->getInterfaceTraffic($router);
                foreach ($interfaces as $iface) {
                    NetworkTraffic::updateOrCreate(
                        ['router_id' => $router->id, 'interface' => $iface['name'],
                         'recorded_at' => now()->startOfMinute()],
                        ['rx_bytes' => $iface['rx-byte'], 'tx_bytes' => $iface['tx-byte'],
                         'rx_packets' => $iface['rx-packet'], 'tx_packets' => $iface['tx-packet']]
                    );
                }
                $router->update(['last_seen_at' => now(), 'status' => 'online']);
            } catch (MikroTikConnectionException $e) {
                $router->update(['status' => 'offline']);
                // Dispatch alert if offline for > 5 minutes
            }
        }
    }
}
```

---

## 10. Security Architecture

### 10.1 Authentication Layers

```
Layer 1: Tenant Resolution (before auth)
Layer 2: Sanctum Token Auth (stateless API)
Layer 3: Role + Permission Check (Spatie)
Layer 4: Policy Authorization (model-level)
Layer 5: Tenant Scope Global (data isolation)
```

All Form Request classes must implement a real `authorize()` method:

```php
public function authorize(): bool {
    return $this->user()->can('record-payments')         // permission check
        && $this->user()->tenant_id === app('tenant')->id; // tenant check
}
```

### 10.2 Secrets & Credential Management

- Router passwords encrypted at rest using Laravel's `Crypt::encrypt()` before storing
- M-Pesa credentials stored in `tenant_settings` (encrypted JSON field)
- SMS gateway API keys stored per tenant (encrypted), never in `config/`
- Use `.env` only for platform-level secrets (database, Redis, Stripe)

### 10.3 M-Pesa Security (Hardened)

```php
// ValidateMpesaCallback.php — HMAC must NOT be optional
public function handle(Request $request, Closure $next): Response {
    $secret = config('services.mpesa.callback_secret');
    abort_if(empty($secret), 500, 'M-Pesa callback secret not configured');

    $signature = hash_hmac('sha256', $request->getContent(), $secret);
    abort_if(
        !hash_equals($signature, $request->header('X-Safaricom-Signature', '')),
        401,
        'Invalid M-Pesa signature'
    );
    return $next($request);
}
```

For M-Pesa STK callback race conditions, use database-level pessimistic locking:

```php
DB::transaction(function() use ($checkoutRequestId) {
    $tx = MpesaTransaction::lockForUpdate()
              ->where('checkout_request_id', $checkoutRequestId)
              ->first();
    if ($tx->status === 'completed') return; // idempotent
    // ... process payment
});
```

### 10.4 Tenant Data Isolation Testing

Add a PHPUnit test that every model query automatically filters by tenant_id:

```php
public function test_global_scope_prevents_cross_tenant_access(): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $clientA = Client::factory()->for($tenantA)->create();

    app()->instance('tenant', $tenantB);

    $this->assertNull(Client::find($clientA->id)); // Must return null
}
```

---

## 11. Scalability & Performance Design

### 11.1 Caching Strategy

```php
// All dashboard stats: cache per tenant, 10-minute TTL
Cache::tags(["tenant:{$tenantId}", 'dashboard'])
     ->remember("dashboard:stats:{$tenantId}", 600, fn() => $this->computeStats());

// Cache invalidation: when payment recorded, bust dashboard cache for that tenant
Cache::tags(["tenant:{$tenantId}", 'dashboard'])->flush();

// Plan list: cache per tenant, 1-hour TTL (changes infrequently)
Cache::tags(["tenant:{$tenantId}", 'plans'])->remember(...)
```

Use `Cache::tags()` for group invalidation so tenant caches never bleed across.

### 11.2 Required Database Indexes

All missing indexes that must be added:

```sql
-- Invoices
ALTER TABLE invoices
    ADD INDEX idx_tenant_status_due   (tenant_id, status, due_date),
    ADD INDEX idx_tenant_client_status (tenant_id, client_id, status);

-- Payments
ALTER TABLE payments
    ADD INDEX idx_tenant_client_date  (tenant_id, client_id, created_at),
    ADD INDEX idx_tenant_invoice      (tenant_id, invoice_id, status);

-- Client accounts
ALTER TABLE client_accounts
    ADD INDEX idx_tenant_expiry       (tenant_id, status, expiry_date),
    ADD INDEX idx_tenant_client       (tenant_id, client_id, status);

-- Audit logs
ALTER TABLE audit_logs
    ADD INDEX idx_tenant_event_time   (tenant_id, event, created_at),
    ADD INDEX idx_tenant_subject      (tenant_id, auditable_type, auditable_id);

-- SMS logs
ALTER TABLE sms_logs
    ADD INDEX idx_tenant_client_date  (tenant_id, client_id, created_at),
    ADD INDEX idx_tenant_status       (tenant_id, status, created_at);

-- Subscriptions (new table)
ADD INDEX idx_tenant_renewal          (tenant_id, status, next_billing_at);

-- Radius sessions
ADD INDEX idx_tenant_account          (tenant_id, client_account_id, started_at);
```

### 11.3 Report Query Optimization

Replace in-PHP grouping with database aggregation:

```php
// BEFORE (loads all rows into memory):
Payment::whereBetween('created_at', [$start, $end])->with('client')->get()
        ->groupBy(fn($p) => $p->created_at->format('Y-m'));

// AFTER (database aggregation):
Payment::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(amount) as total, COUNT(*) as count')
        ->where('tenant_id', $tenantId)
        ->whereBetween('created_at', [$start, $end])
        ->groupBy('month')
        ->orderBy('month')
        ->get();
```

For large report generation, dispatch `GenerateReportJob` and return a job ID. Frontend polls for completion, then downloads the generated file from storage.

### 11.4 API Response Performance

- Use **API Resources** (`JsonResource`) on all endpoints — never `->toArray()` on collections
- Use `->cursorPaginate()` for large list endpoints (faster than offset pagination)
- Add `ETag` headers on resource endpoints for client-side cache validation
- Enable MySQL query cache for tenant-scoped read-heavy endpoints

---

## 12. Notification & Communication Architecture

### 12.1 Notification Channel Abstraction

Extend the current SMS-only system to a full multi-channel notification system using Laravel Notifications:

```php
// Channels: SMS, Email, WhatsApp, In-app (database)
class InvoiceGeneratedNotification extends Notification {
    public function via(object $notifiable): array {
        $prefs = $notifiable->notification_preferences;
        return array_filter([
            $prefs['sms']      ? 'sms'      : null,
            $prefs['email']    ? 'mail'      : null,
            $prefs['whatsapp'] ? 'whatsapp'  : null,
            'database',                           // always store in-app
        ]);
    }
}
```

### 12.2 Notification Tables

```sql
notification_templates (
    id, tenant_id,
    event         VARCHAR(100),  -- 'invoice.generated'
    channel       ENUM('sms','email','whatsapp','push'),
    subject       VARCHAR(300) NULL,
    body          TEXT,           -- supports {{client_name}}, {{amount}} variables
    is_active     TINYINT(1) DEFAULT 1
)

notification_log (
    id, tenant_id, client_id,
    channel, event, template_id,
    recipient     VARCHAR(255),
    body          TEXT,
    status        ENUM('queued','sent','delivered','failed','bounced'),
    gateway_ref   VARCHAR(200) NULL,
    sent_at       TIMESTAMP NULL,
    delivered_at  TIMESTAMP NULL,
    INDEX idx_tenant_client (tenant_id, client_id, created_at)
)

sms_campaigns (
    id, tenant_id, user_id,
    name, segment_filter JSON,   -- filter criteria for client selection
    template_id BIGINT NULL,
    body TEXT,
    status ENUM('draft','scheduled','running','completed','failed'),
    total_recipients INT DEFAULT 0,
    sent_count       INT DEFAULT 0,
    scheduled_at     TIMESTAMP NULL,
    completed_at     TIMESTAMP NULL
)
```

### 12.3 Notification Events

Every domain event should dispatch a notification. Minimum required events:

| Event | Channels |
|-------|----------|
| Account activated | SMS + Email |
| Invoice generated | SMS + Email |
| Invoice due in 3 days | SMS |
| Invoice overdue | SMS + Email |
| Payment received | SMS + Email |
| Account suspended | SMS + Email |
| Account restored | SMS |
| FUP threshold 80% | SMS |
| FUP speed reduced | SMS |
| FUP speed restored | SMS |
| Password reset | SMS |
| New ticket reply | Email |
| Voucher activated | SMS |

---

## 13. Reporting & Analytics Architecture

### 13.1 Report Types (Complete)

| Report | Existing | Notes |
|--------|----------|-------|
| Income Report | ✅ Partial | Add tax breakdown, payment method split |
| Client Report | ✅ Partial | Add churn rate, cohort analysis |
| Invoice Report | ✅ Partial | Add aging buckets (30/60/90 day) |
| SMS Report | ✅ | |
| Network Report | ⚠️ Partial | Needs real traffic data (router polling stub is empty) |
| Inventory Report | ✅ | |
| Agent Commission Report | ❌ Missing | |
| FUP Usage Report | ❌ Missing | |
| RADIUS Session Report | ❌ Missing | |
| Dunning Effectiveness Report | ❌ Missing | |
| SLA Compliance Report | ❌ Missing | |
| Voucher Sales Report | ❌ Missing | |
| AR Aging Report | ❌ Missing | |
| Revenue Forecast | ❌ Missing | Based on active subscription MRR |

### 13.2 Async Report Generation

All reports that could exceed 5 seconds must be generated asynchronously:

```
POST /api/v1/admin/reports/generate
Body: { "type": "income", "start": "2026-01-01", "end": "2026-05-31", "format": "xlsx" }

Response: { "job_id": "uuid", "status": "queued" }

GET /api/v1/admin/reports/status/{job_id}
Response: { "status": "completed", "download_url": "..." }
```

Store generated reports in S3/DigitalOcean Spaces with a 24-hour presigned URL.

### 13.3 Dashboard KPI Caching

```php
// Dashboard data refreshed every 10 minutes per tenant
// Cache keys namespaced by tenant to prevent bleed
[
    "dashboard:{tenant_id}:stats"           => 10 min
    "dashboard:{tenant_id}:top_downloaders" => 15 min
    "dashboard:{tenant_id}:traffic_graph"   => 5 min
    "dashboard:{tenant_id}:revenue_chart"   => 30 min
    "dashboard:{tenant_id}:recent_payments" => 5 min
]
```

---

## 14. Deployment & Infrastructure

### 14.1 Minimum Production Infrastructure

```
Load Balancer (Nginx)
    ├── App Server 1 (Laravel — PHP-FPM, 4 CPU, 8GB RAM)
    ├── App Server 2 (Laravel — PHP-FPM, 4 CPU, 8GB RAM)
    └── Queue Worker Server (Horizon, 4 CPU, 8GB RAM)
            ├── 4 workers: critical queue
            ├── 4 workers: provisioning queue
            ├── 8 workers: billing queue
            └── 8 workers: notifications queue

Database: MySQL 8 (Primary + 1 Read Replica)
    - Read replica for reports and dashboard queries
    - Primary for all writes

Cache/Queue: Redis Cluster (2 nodes)
    - Separate Redis DB indexes: 0=cache, 1=queues, 2=sessions

Object Storage: S3 or DigitalOcean Spaces
    - Invoice PDFs, report exports, client documents

Monitoring: Laravel Telescope (dev) + Sentry (prod) + Horizon dashboard
```

### 14.2 Environment Variables (Complete)

```dotenv
# App
APP_NAME=PrimeBill
APP_ENV=production
APP_KEY=
APP_URL=https://app.primebill.co.ke
ASSET_URL=

# Database
DB_HOST=
DB_DATABASE=primebill_prod
DB_USERNAME=
DB_PASSWORD=
DB_READ_HOST=    # Read replica

# Redis
REDIS_HOST=
REDIS_PASSWORD=
REDIS_PORT=6379
REDIS_CACHE_DB=0
REDIS_QUEUE_DB=1
REDIS_SESSION_DB=2

# Queue
QUEUE_CONNECTION=redis
HORIZON_ENABLED=true

# Storage
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=

# M-Pesa (Platform level — per-tenant stored in DB)
MPESA_ENVIRONMENT=production
MPESA_CALLBACK_SECRET=  # HMAC secret — must never be empty

# Email
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=

# Monitoring
SENTRY_LARAVEL_DSN=
TELESCOPE_ENABLED=false  # false in production
```

---

## 15. Implementation Roadmap

### Phase 1 — Foundation Fixes (Weeks 1–4)

Critical bugs and security fixes before any new feature work:

1. **Create `Subscription` model** and complete `SubscriptionService` or remove the dead code
2. **Implement `PollRouterTraffic`** job with real MikroTik interface polling
3. **Add `tenant_id`** to all models and migrations with global scope trait
4. **Tenant resolution middleware** and route registration per tenant type
5. **Fix `StorePaymentRequest::authorize()`** and all other Form Requests
6. **Make M-Pesa HMAC validation non-optional** with proper abort
7. **Fix N+1 queries** in InvoiceService, DashboardService, ReportService
8. **Add missing composite indexes** (invoices, payments, client_accounts)
9. **Replace synchronous SMS loops** with `SendSmsJob::dispatch()` calls
10. **Fix orphaned migration** — remove references to non-existent tables
11. **Dashboard caching** — 10-minute cache with tenant-tagged invalidation
12. **Standardize API responses** — `ApiResponse` trait in every controller

### Phase 2 — Core SaaS Features (Weeks 5–10)

13. **Complete Subscription Management** — full lifecycle with auto-renewal
14. **Dunning Workflow** — configurable policies, escalating steps, speed restriction
15. **Wallet & Credit System** — overpayment handling, auto-application
16. **Invoice Line Items** — proration, addons, one-off charges
17. **Tax Management** — VAT 16%, configurable rates per tenant
18. **Tenant Onboarding Flow** — signup, setup wizard, default seed data
19. **Platform Tenant Billing** — meter usage, charge ISPs for using PrimeBill
20. **White-labeling** — per-tenant logo, colors, company name in invoices/emails
21. **RBAC per tenant** — tenant admins manage their own staff permissions

### Phase 3 — Network & Provisioning (Weeks 11–16)

22. **IPAM** — IP pool management, auto-allocation on provisioning
23. **FUP Engine** — sync usage, tier detection, CoA packets, auto-restore
24. **Network Abstraction Layer** — driver interface for MikroTik, RADIUS, Ubiquiti
25. **RADIUS Accounting Webhook** — receive interim/stop records, update usage
26. **Hotspot & Voucher System** — zone management, voucher batch generation
27. **SLA Tracking** — incident logging, uptime calculation, monthly reports

### Phase 4 — Ecosystem & Growth (Weeks 17–24)

28. **Agent & Commission Management** — reseller portal, payout tracking
29. **Webhook Engine** — configurable endpoints, delivery with retry
30. **SMS Campaign Manager** — segmented bulk campaigns, scheduling
31. **Advanced Reporting** — AR aging, FUP usage, RADIUS sessions, revenue forecast
32. **KYC Module** — document uploads, verification workflow
33. **Client Mobile PWA** — push notifications, self-service payments
34. **API Keys** — machine-to-machine access per tenant (for external integrations)
35. **Audit Trail Enhancement** — field-level change tracking on all models

---

## Appendix: Module Completeness Matrix

| Module | Current Status | Multi-Tenant Ready | Priority |
|--------|---------------|-------------------|----------|
| Authentication | ✅ Complete | ❌ No tenant scope | P1 |
| Client Management | ⚠️ Partial | ❌ No tenant scope | P1 |
| Subscription Management | ❌ Broken | ❌ | P1 |
| Billing Engine | ⚠️ Partial | ❌ No tenant scope | P1 |
| Payment Processing | ⚠️ Partial | ❌ No tenant scope | P1 |
| M-Pesa Integration | ✅ Mostly | ❌ Race condition | P1 |
| Dunning Management | ❌ Missing | ❌ | P2 |
| FUP Engine | ❌ Missing | ❌ | P2 |
| Wallet / Credit System | ❌ Missing | ❌ | P2 |
| Tax Management | ❌ Missing | ❌ | P2 |
| IPAM | ❌ Missing | ❌ | P3 |
| Hotspot / Vouchers | ❌ Missing | ❌ | P3 |
| Network Abstraction | ⚠️ Partial | N/A | P3 |
| RADIUS Accounting | ⚠️ Partial | ❌ | P3 |
| Agent / Commissions | ❌ Missing | ❌ | P4 |
| Webhook Engine | ❌ Missing | ❌ | P4 |
| SMS Campaigns | ⚠️ Partial | ❌ | P4 |
| SLA Tracking | ❌ Missing | ❌ | P4 |
| KYC / Documents | ❌ Missing | ❌ | P4 |
| Platform Billing (SaaS) | ❌ Missing | N/A | P2 |
| Audit Trail | ⚠️ Partial | ❌ No indexes | P1 |
| Reporting | ⚠️ Partial | ❌ No tenant scope | P2 |
| Tenant Management | ❌ Missing | N/A | P1 |
| White-labeling | ❌ Missing | N/A | P2 |

---

*This document should be reviewed alongside `CLAUDE.md` and the `primebill-api/README.md`. The implementation roadmap is sequenced so that each phase builds on a stable foundation from the previous phase.*
