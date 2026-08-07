# PrimeBill OSS/BSS Production Blueprint v1.0

> **Document Type:** Production Readiness Blueprint & Implementation Guide  
> **Version:** 1.0  
> **Date:** August 2026  
> **Status:** ACTIVE — Authoritative reference for production deployment  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current State Assessment](#2-current-state-assessment)
3. [Platform Foundation](#3-platform-foundation)
4. [CRM & Customer Lifecycle](#4-crm--customer-lifecycle)
5. [Service Management](#5-service-management)
6. [Billing Engine](#6-billing-engine)
7. [MikroTik & RADIUS](#7-mikrotik--radius)
8. [IPAM](#8-ipam)
9. [Network Monitoring](#9-network-monitoring)
10. [Fiber / OLT Management](#10-fiber--olt-management)
11. [Inventory](#11-inventory)
12. [Field Operations](#12-field-operations)
13. [Customer Portal](#13-customer-portal)
14. [Mobile APIs](#14-mobile-apis)
15. [Analytics](#15-analytics)
16. [Reporting](#16-reporting)
17. [Integrations](#17-integrations)
18. [Security](#18-security)
19. [Testing](#19-testing)
20. [Documentation](#20-documentation)
21. [Deployment](#21-deployment)
22. [Production Readiness Checklist](#22-production-readiness-checklist)

---

## 1. Executive Summary

PrimeBill is a Laravel-based SaaS ISP billing and OSS/BSS platform designed for small-to-medium ISPs in Kenya and Africa. The platform provides comprehensive customer management, billing, payment processing (M-Pesa, banks, cards), network provisioning (MikroTik, FreeRADIUS), and operational tools.

### 1.1 Current State

| Component | Status | Notes |
|-----------|--------|-------|
| Core Billing | 90% | Invoices, payments, M-Pesa STK/C2B, bulk generation |
| Network Provisioning | 80% | MikroTik + RADIUS adapters with mock drivers |
| Multi-Tenancy | 90% | Tenant tables created, tenant_id columns added |
| Authentication & RBAC | 95% | Sanctum + Spatie permissions implemented |
| Frontend (Admin) | 85% | React SPA with leads/prospects modules complete |
| Testing | 70% | CI present, coverage expanding |
| Production Hardening | 60% | Missing PDF receipts, some security gaps |

### 1.2 Production Blockers

**Must fix before launch:**
- [ ] Subscription model/table mismatch (broken service)
- [ ] M-Pesa callback HMAC validation (currently optional)
- [ ] StorePaymentRequest authorization bypass
- [ ] N+1 query performance issues
- [ ] Missing database composite indexes
- [ ] PollRouterTraffic empty stub
- [ ] Cross-tenant access prevention tests
- [ ] PDF receipt generation
- [ ] Email templates for invoices/reminders
- [ ] Dashboard caching implementation

---

## 2. Current State Assessment

### 2.1 Completed Features ✅

**Authentication & Security**
- Email/password authentication (Sanctum)
- Password reset flow
- Role-based access control (Spatie Permissions)
- API token management
- Session management
- MFA/2FA ready (foundation present)

**Customer Management**
- Client registration and profiles
- Account suspension/activation
- Multiple service accounts per client
- Client search

**Billing & Payments**
- Invoice generation (manual, bulk, recurring)
- M-Pesa STK Push + C2B
- Payment reconciliation with idempotency
- Tax calculation (VAT 16%)
- Outstanding balance tracking
- Expenditure tracking

**Network**
- MikroTik RouterOS API integration
- FreeRADIUS schema and adapters
- RADIUS accounting webhook
- Service provisioning jobs (create/update/delete)
- Traffic polling (stub exists)

**Notifications**
- SMS via Africa's Talking + Hostpinnacle
- Scheduled payment reminders
- Invoice notifications

**Reporting**
- Income reports
- Client analytics
- Invoice aging
- Churn analysis
- Dashboard statistics

**Multi-Tenancy**
- Tenants table with domains
- Tenant_id columns on major tables
- Platform admin separation
- Tenant resolution middleware

### 2.2 Partially Implemented ⚠️

| Feature | Gap | Priority |
|---------|-----|----------|
| Subscription Management | Model/table mismatch, service broken | P1 |
| Email Notifications | Config present, mailables missing | P1 |
| PDF Receipts | JSON only, PDF generation missing | P1 |
| Network Traffic Polling | Command stub, not implemented | P1 |
| Dashboard Performance | No caching, N+1 queries | P1 |
| Audit Logs | Basic SystemLog, missing field-level tracking | P2 |
| Hotspot/Vouchers | Schema present, provisioning incomplete | P2 |
| FUP Enforcement | Fields on plans, no enforcement logic | P2 |
| Reporting Exports | CSV missing, Excel/PDF missing | P2 |

### 2.3 Missing Features ❌

**Critical (P1)**
- Cross-tenant data isolation enforcement
- Dunning workflow (grace periods, escalation)
- Wallet/credit system
- Invoice line items (proration, addons)
- Tax management (multiple rates)
- Tenant onboarding flow

**High (P2)**
- IP Address Management (IPAM)
- FUP enforcement engine
- Agent/commission management
- Webhook engine
- White-labeling/branding
- SLA tracking
- KYC/document management

**Medium (P3)**
- Advanced analytics (ARPU, LTV, forecasts)
- Network map visualization
- Bulk operations optimization
- Customer groups/tags

**Low (P4)**
- Client mobile PWA
- Commission payout workflows
- Advanced reporting (custom reports builder)
| Integration marketplace

---

## 3. Platform Foundation

### 3.1 Multi-Tenancy

**Status:** 90% Complete

#### Required Components

| Component | Status | Implementation |
|-----------|--------|----------------|
| Tenant management | ✅ Done | `Tenant` model, CRUD endpoints |
| Tenant isolation | ⚠️ Partial | Global scopes on some models, needs enforcement |
| Tenant settings | ✅ Done | JSON settings column on tenants table |
| Tenant branding | ✅ Done | JSON branding column (logo, colors) |
| Tenant domains/subdomains | ✅ Done | `tenant_domains` table with lookup |
| Tenant quotas | ✅ Done | `tenant_limits` table |
| Tenant subscription plans | ✅ Done | `tenant_subscriptions` table |
| Tenant billing | ❌ Missing | Platform billing for SaaS metering |

#### Database Schema

```sql
-- Core tenant record
CREATE TABLE tenants (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    custom_domain VARCHAR(255) NULL,
    plan ENUM('starter','growth','enterprise') DEFAULT 'starter',
    status ENUM('active','suspended','trial','cancelled') DEFAULT 'trial',
    trial_ends_at TIMESTAMP NULL,
    settings JSON NOT NULL DEFAULT ('{}'),
    branding JSON NOT NULL DEFAULT ('{}'),
    timezone VARCHAR(100) DEFAULT 'Africa/Nairobi',
    currency CHAR(3) DEFAULT 'KES',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_custom_domain (custom_domain)
);

-- Domain mapping
CREATE TABLE tenant_domains (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(255) UNIQUE NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    verified_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    INDEX idx_domain (domain)
);

-- Feature limits
CREATE TABLE tenant_limits (
    tenant_id BIGINT UNSIGNED PRIMARY KEY,
    max_clients INT DEFAULT 500,
    max_routers INT DEFAULT 10,
    max_sms_per_month INT DEFAULT 5000,
    max_users INT DEFAULT 5,
    can_white_label TINYINT(1) DEFAULT 0,
    can_use_api TINYINT(1) DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
```

#### Backend Implementation

**TenantMiddleware** — Resolves tenant from subdomain/custom domain/API key:
```php
// app/Http/Middleware/ResolveTenant.php
public function handle(Request $request, Closure $next): Response {
    $tenant = Tenant::resolveFromRequest($request);
    if (!$tenant) abort(404);
    app()->instance('tenant', $tenant);
    return $next($request);
}
```

**TenantResolver** — Static helper for tenant detection:
```php
// app/Services/TenantResolver.php
public static function resolveFromRequest(Request $request): ?Tenant {
    // 1. Check subdomain
    // 2. Check custom domain
    // 3. Check X-Tenant-ID header
}
```

**BelongsToTenant Trait** — Global scope + auto-set on create:
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

#### Tests Required

- [ ] Cross-tenant access prevention (query returns null for other tenant's data)
- [ ] Tenant resolution from subdomain
- [ ] Tenant resolution from custom domain
- [ ] Tenant resolution from API header
- [ ] Tenant settings inheritance
- [ ] Tenant quota enforcement
- [ ] Tenant onboarding flow

---

### 3.2 Authentication

**Status:** 95% Complete

#### Required Features

| Feature | Status | Notes |
|---------|--------|-------|
| Email/password auth | ✅ Done | Sanctum tokens |
| Password reset | ✅ Done | Forgot/reset endpoints |
| Email verification | ⚠️ Partial | Foundation present, flow incomplete |
| MFA/2FA | ⚠️ Partial | Ready in Sanctum, not enforced |
| API tokens | ✅ Done | Sanctum token management |
| Session management | ✅ Done | Web guard + Sanctum |
| Device tracking | ❌ Missing | Token device tracking |
| Login history | ❌ Missing | Audit log integration needed |
| RBAC | ✅ Done | Spatie permissions seeded |

#### Roles

| Role | Permissions |
|------|-------------|
| Super Admin | All platform-level permissions |
| Tenant Admin | All tenant permissions + user management |
| Finance Manager | billing, payments, reports |
| Network Engineer | network management, routers, RADIUS |
| Technician | inventory, work orders, installations |
| Customer Support | tickets, clients (read-only billing) |
| Customer | Portal access only |

#### Permissions Matrix

```php
// database/seeders/RolesAndPermissionsSeeder.php
Permission::create(['name' => 'customer management', 'group' => 'operations']);
Permission::create(['name' => 'billing', 'group' => 'finance']);
Permission::create(['name' => 'network management', 'group' => 'technical']);
Permission::create(['name' => 'inventory', 'group' => 'operations']);
Permission::create(['name' => 'reports', 'group' => 'analytics']);
Permission::create(['name' => 'settings', 'group' => 'administration']);
```

---

### 3.3 Settings

**Status:** 85% Complete

#### Required Settings

| Category | Settings | Status |
|----------|----------|--------|
| System | App name, timezone, currency, date format | ✅ Done |
| Tenant | Branding, limits, features | ✅ Done |
| Email | SMTP host, port, encryption, from address | ⚠️ Partial |
| SMS | Gateway provider, API key, sender ID | ✅ Done |
| Payment | M-Pesa keys, gateway config | ✅ Done |
| MikroTik | API host, port, credentials | ✅ Done |
| RADIUS | Server host, port, secret | ✅ Done |
| Branding | Logo, primary color, favicon | ✅ Done |

#### Implementation

```php
// app/Services/SettingsService.php
public function get(string $key, mixed $default = null): mixed {
    $settings = Cache::remember("settings:{$this->tenantId}", 3600, function() {
        return Setting::where('tenant_id', $this->tenantId)
                     ->pluck('value', 'key')
                     ->toArray();
    });
    return $settings[$key] ?? $default;
}

public function set(string $key, mixed $value): void {
    Setting::updateOrCreate(
        ['tenant_id' => $this->tenantId, 'key' => $key],
        ['value' => $value]
    );
    Cache::forget("settings:{$this->tenantId}");
}
```

---

### 3.4 Audit Logs

**Status:** 85% Complete

#### Track Events

| Event Type | Examples |
|------------|----------|
| User actions | Login, logout, password change, role change |
| Billing changes | Invoice created/voided, payment recorded, credit note |
| Network changes | Router added, interface changed, firewall rule |
| Configuration changes | Settings updated, plan modified |
| Login activity | Successful login, failed attempt, MFA challenge |

#### Database Schema

```sql
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    user_type ENUM('admin','staff','client','system','api') NOT NULL,
    event VARCHAR(100) NOT NULL,
    auditable_type VARCHAR(100) NULL,
    auditable_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL,
    INDEX idx_tenant_event (tenant_id, event, created_at),
    INDEX idx_tenant_subject (tenant_id, auditable_type, auditable_id),
    INDEX idx_tenant_user (tenant_id, user_id, created_at)
);
```

#### Implementation

```php
// app/Models/Traits/Auditable.php
trait Auditable {
    protected static function bootAuditable(): void {
        static::created(fn($model) => $model->logAudit('created'));
        static::updated(fn($model) => $model->logAudit('updated'));
        static::deleted(fn($model) => $model->logAudit('deleted'));
    }

    protected function logAudit(string $event): void {
        AuditLog::create([
            'tenant_id' => app('tenant')->id,
            'user_id' => auth()->id(),
            'user_type' => $this->auditUserType(),
            'event' => get_class($this) . '.' . $event,
            'auditable_type' => get_class($this),
            'auditable_id' => $this->id,
            'old_values' => $this->getOriginalAttributes(),
            'new_values' => $this->getAttributes(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
        ]);
    }
}
```

---

### 3.5 Notifications

**Status:** 85% Complete

#### Channels

| Channel | Status | Provider |
|---------|--------|----------|
| Email | ⚠️ Partial | SMTP configured, mailables incomplete |
| SMS | ✅ Done | Africa's Talking + Hostpinnacle |
| WhatsApp | ✅ Done | Africa's Talking WhatsApp API |
| Push | ❌ Missing | Firebase/OneSignal not integrated |
| In-app | ✅ Done | Database notifications table |

#### Events & Triggers

| Event | SMS | Email | WhatsApp | Push |
|-------|-----|-------|----------|------|
| Invoice generated | ✅ | ⚠️ | ❌ | ❌ |
| Payment received | ✅ | ⚠️ | ❌ | ❌ |
| Suspension warning | ✅ | ⚠️ | ❌ | ❌ |
| Service activated | ✅ | ⚠️ | ❌ | ❌ |
| Ticket updates | ❌ | ⚠️ | ❌ | ❌ |
| Password reset | ❌ | ✅ | ❌ | ❌ |

#### Implementation Pattern

```php
// app/Notifications/InvoiceGeneratedNotification.php
class InvoiceGeneratedNotification extends Notification {
    public function via(object $notifiable): array {
        $prefs = $notifiable->notification_preferences;
        return array_filter([
            $prefs['sms'] ? 'sms' : null,
            $prefs['email'] ? 'mail' : null,
            $prefs['whatsapp'] ? 'whatsapp' : null,
            'database',
        ]);
    }

    public function toSms($notifiable) { /* ... */ }
    public function toMail($notifiable) { /* ... */ }
    public function toWhatsApp($notifiable) { /* ... */ }
}
```

---

## 4. CRM & Customer Lifecycle

### 4.1 Leads

**Status:** Implemented (Frontend complete)

**Features:**
- Lead capture form
- Lead source tracking (website, referral, cold call)
- Lead status workflow
- Lead assignment to sales agents
- Lead notes and history
- Conversion tracking to prospect/client

**Statuses:**
```
New → Contacted → Qualified → Survey Required → Converted → Lost
```

**Database:** `leads` table with tenant_id, assigned_to, source, status, notes

---

### 4.2 Prospects

**Status:** Implemented (Frontend complete)

**Features:**
- Customer interest tracking
- Package selection assistance
- Installation feasibility notes
- Sales pipeline visualization
- Convert to client workflow

**Database:** `prospects` table linked to leads

---

### 4.3 Customers

**Status:** 90% Complete

#### Features Required

| Feature | Status | Notes |
|---------|--------|-------|
| Customer profile | ✅ Done | Name, email, phone, ID number |
| Account number | ✅ Done | Auto-generated unique account number |
| Customer status | ✅ Done | active, suspended, terminated |
| Customer history | ✅ Done | Timeline of all events |
| Customer documents | ❌ Missing | KYC, contracts, receipts storage |
| Customer timeline | ⚠️ Partial | Basic audit log, needs enhancement |

#### Contacts

| Type | Status | Notes |
|------|--------|-------|
| Primary contact | ✅ Done | Main contact person |
| Billing contact | ✅ Done | Separate billing email/phone |
| Technical contact | ⚠️ Partial | Field exists, not prominently used |
| Emergency contact | ❌ Missing | Not implemented |

#### Addresses

| Type | Status | Notes |
|------|--------|-------|
| Installation address | ✅ Done | GPS coordinates, coverage validation |
| Billing address | ⚠️ Partial | Same as installation by default |
| Multiple addresses | ❌ Missing | Not implemented |

#### Documents

| Type | Status | Notes |
|------|--------|-------|
| ID upload | ❌ Missing | KYC not implemented |
| Contract upload | ❌ Missing | Digital contract storage |
| Installation forms | ❌ Missing | Not implemented |
| Receipts | ⚠️ Partial | JSON receipt, PDF missing |

**Required Implementation:**
```php
// app/Models/ClientDocument.php
class ClientDocument extends Model {
    use BelongsToTenant;
    
    protected $fillable = [
        'tenant_id', 'client_id', 'type', 'path', 'status',
        'verified_by', 'verified_at', 'expires_at'
    ];
    
    public function client() { return $this->belongsTo(Client::class); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
}
```

#### Timeline Events

| Event | Trigger |
|-------|---------|
| Created | Client registered |
| Activated | Service provisioned |
| Payment | Payment received |
| Suspension | Account suspended |
| Installation | Technician visit completed |
| Support ticket | Ticket created/updated |
| Plan change | Subscription upgraded/downgraded |

---

### 4.4 Customer Portal

**Status:** 60% Complete (Backend present, frontend incomplete)

#### Required Features

| Feature | Status | Notes |
|---------|--------|-------|
| Dashboard | ✅ Done | Stats endpoint exists |
| View invoices | ✅ Done | API endpoint ready |
| Pay bills | ✅ Done | M-Pesa STK integration |
| View usage | ⚠️ Partial | Basic, needs FUP display |
| Manage profile | ✅ Done | Profile update endpoints |
| Open tickets | ✅ Done | Ticket CRUD in portal |
| Download documents | ❌ Missing | Document storage needed |
| Notifications | ⚠️ Partial | In-app only |
| Service status | ✅ Done | Status endpoint |

**Portal Routes:**
```
/api/portal/dashboard
/api/portal/invoices
/api/portal/invoices/{id}/pay
/api/portal/usage
/api/portal/tickets
/api/portal/profile
/api/portal/documents
```

---

## 5. Service Management

### 5.1 Service Instances

**Status:** 85% Complete

#### Supported Service Types

| Type | Status | Notes |
|------|--------|-------|
| Fiber | ✅ Done | PPPoE with VLAN tagging |
| PPPoE | ✅ Done | Username/password, profiles |
| Hotspot | ⚠️ Partial | Voucher system exists, captive portal missing |
| Dedicated Internet | ⚠️ Partial | Static IP support, SLA missing |
| Static IP | ✅ Done | IP allocation tracking |
| VPN | ❌ Missing | Not implemented |
| Point-to-point | ❌ Missing | Not implemented |

#### Service Features

| Feature | Status | Implementation |
|---------|--------|----------------|
| Username/password | ✅ Done | RADIUS integration |
| Profiles | ✅ Done | MikroTik/RADIUS profiles |
| Bandwidth limits | ✅ Done | Plan-based speed_up/down |
| RADIUS sync | ✅ Done | Provisioning jobs |
| Disconnect/reconnect | ✅ Done | Suspend/activate jobs |

**Database:** `client_accounts` table with service_type, username, password, profile, router_id

---

### 5.2 Hotspot

**Status:** 85% Complete

#### Features Required

| Feature | Status | Notes |
|---------|--------|-------|
| Voucher users | ✅ Done | Voucher model + batches |
| Captive portal | ❌ Missing | MikroTik hotspot profile config |
| Expiry handling | ✅ Done | Scheduled expiry job |
| Zone management | ❌ Missing | Hotspot zones not implemented |

**Database Schema:**
```sql
CREATE TABLE hotspot_zones (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    profile_name VARCHAR(100) NOT NULL,
    login_url VARCHAR(255),
    walled_garden_urls TEXT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (router_id) REFERENCES routers(id)
);

CREATE TABLE voucher_batches (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    zone_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    batch_name VARCHAR(100),
    quantity INT,
    price DECIMAL(10,2),
    validity_hours INT,
    created_by BIGINT UNSIGNED,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE vouchers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('unused','active','expired','disabled') DEFAULT 'unused',
    activated_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    client_mac VARCHAR(20) NULL,
    mikrotik_username VARCHAR(100) NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (batch_id) REFERENCES voucher_batches(id)
);
```

---

### 5.3 Packages (Plans)

**Status:** 95% Complete

| Feature | Status |
|---------|--------|
| Plan CRUD | ✅ Done |
| Bandwidth profiles | ✅ Done |
| FUP configuration | ⚠️ Partial | Fields exist, enforcement missing |
| Pricing | ✅ Done |
| Package assignment | ✅ Done |
| Service period | ✅ Done |

---

### 5.4 Enterprise Services

**Status:** Partial

| Feature | Status | Notes |
|---------|--------|-------|
| VLAN support | ⚠️ Partial | VLAN tagging on accounts, not management |
| Point-to-point links | ❌ Missing | Not implemented |
| Dedicated bandwidth | ✅ Done | Static plans with no FUP |
| SLA tracking | ❌ Missing | Schema exists, logic missing |

---

### 5.5 Lifecycle Actions

**Status:** 90% Complete

| Action | Status | Implementation |
|--------|--------|----------------|
| Activate | ✅ Done | ProvisionClientAccountJob |
| Suspend | ✅ Done | SuspendServiceJob |
| Resume | ✅ Done | ResumeServiceJob |
| Upgrade | ✅ Done | Plan change with provisioning |
| Downgrade | ✅ Done | Plan change with provisioning |
| Relocate | ❌ Missing | Router/port change not implemented |
| Terminate | ✅ Done | Deprovision + release IP |

---

## 6. Billing Engine

### 6.1 Invoices

**Status:** 90% Complete

| Feature | Status | Notes |
|---------|--------|-------|
| Automatic generation | ✅ Done | Monthly scheduled command |
| Recurring invoices | ✅ Done | Based on subscription cycle |
| Manual invoices | ✅ Done | Admin can create |
| Invoice templates | ❌ Missing | Single template, no customization |
| PDF generation | ❌ Missing | JSON receipt only |

**Required Enhancements:**
```sql
-- Invoice line items for proration/addons
CREATE TABLE invoice_line_items (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255),
    quantity DECIMAL(10,2) DEFAULT 1,
    unit_price DECIMAL(12,2),
    tax_rate DECIMAL(5,2) DEFAULT 0,
    total DECIMAL(12,2),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id)
);

-- Invoice templates per tenant
CREATE TABLE invoice_templates (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100),
    header_html TEXT,
    footer_html TEXT,
    is_default TINYINT(1) DEFAULT 0,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
```

---

### 6.2 Payments

**Status:** 90% Complete

| Method | Status | Implementation |
|--------|--------|----------------|
| M-Pesa | ✅ Done | STK Push + C2B callbacks |
| Bank | ⚠️ Partial | Manual recording only |
| Card | ❌ Missing | Stripe/Pesapal not integrated |
| Cash | ✅ Done | Manual recording |

| Feature | Status | Notes |
|---------|--------|-------|
| Reconciliation | ✅ Done | Idempotent callback processing |
| Receipts | ⚠️ Partial | JSON only, PDF missing |
| Refunds | ❌ Missing | Full refund workflow missing |
| Partial payments | ❌ Missing | Not supported |
| Credit notes | ❌ Missing | Not implemented |

**Required Tables:**
```sql
CREATE TABLE payment_gateways (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50), -- mpesa, stripe, pesapal
    config JSON, -- encrypted credentials
    is_active TINYINT(1) DEFAULT 1,
    is_default TINYINT(1) DEFAULT 0,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE payment_refunds (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2),
    reason TEXT,
    status ENUM('pending','approved','rejected','completed'),
    processed_by BIGINT UNSIGNED,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
);
```

---

### 6.3 Credit Notes & Adjustments

**Status:** Missing

| Feature | Implementation |
|---------|----------------|
| Customer credits | Wallet system (see below) |
| Adjustments | Admin can issue credit notes |
| Debit notes | Not implemented |

---

### 6.4 Ledger & Double-Entry

**Status:** 80% Complete

| Feature | Status | Notes |
|---------|--------|-------|
| Ledger entries | ✅ Done | `ledger_entries` table |
| Balance calculation | ✅ Done | Running balance maintained |
| Transactions history | ✅ Done | Linked to invoices/payments |
| Double-entry | ⚠️ Partial | Basic, needs audit |

---

### 6.5 Wallet System

**Status:** Missing

**Required Implementation:**
```sql
CREATE TABLE client_wallets (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL UNIQUE,
    balance DECIMAL(12,2) DEFAULT 0.00,
    currency CHAR(3) DEFAULT 'KES',
    last_activity TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE wallet_transactions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    wallet_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    type ENUM('credit','debit','refund','adjustment'),
    amount DECIMAL(12,2),
    running_balance DECIMAL(12,2),
    reference_type VARCHAR(50),
    reference_id BIGINT,
    description TEXT,
    recorded_by BIGINT UNSIGNED,
    created_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (wallet_id) REFERENCES client_wallets(id)
);
```

---

### 6.6 Taxes

**Status:** Partial

| Feature | Status | Notes |
|---------|--------|-------|
| VAT | ✅ Done | Auto-applied at 16% |
| Multiple tax rates | ❌ Missing | Single rate only |
| Tax per line item | ❌ Missing | Applied to total only |

**Required:**
```sql
CREATE TABLE tax_rates (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100),
    rate DECIMAL(5,2),
    type ENUM('inclusive','exclusive'),
    applies_to ENUM('all','plans','addons'),
    is_default TINYINT(1) DEFAULT 0,
    is_compound TINYINT(1) DEFAULT 0,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
```

---

### 6.7 Discounts

**Status:** Missing

| Type | Implementation |
|------|----------------|
| Promotions | Not implemented |
| Coupons | Not implemented |
| Customer discounts | Not implemented |

---

### 6.8 Recurring Billing

**Status:** 90% Complete

| Feature | Status | Implementation |
|---------|--------|----------------|
| Monthly plans | ✅ Done | Default billing cycle |
| Automatic invoices | ✅ Done | Scheduled monthly job |
| Auto-renew | ⚠️ Partial | Jobs exist, subscription model broken |
| Proration | ❌ Missing | Not implemented |

---

### 6.9 Usage Billing

**Status:** Missing

| Feature | Implementation |
|---------|----------------|
| Data usage billing | Not implemented |
| Overage charges | Not implemented |
| FUP metering | In development |

---

### 6.10 Finance Reports

**Status:** 85% Complete

| Report | Status |
|--------|--------|
| Revenue | ✅ Done |
| Outstanding invoices | ✅ Done |
| Aging | ✅ Done |
| Collections | ✅ Done |
| Profit/Loss | ⚠️ Partial | Needs expense categorization |

---

## 7. MikroTik & RADIUS

### 7.1 MikroTik Management

**Status:** 80% Complete

| Feature | Status | Implementation |
|---------|--------|----------------|
| Router management | ✅ Done | Router CRUD + connection test |
| API connection | ✅ Done | RouterOS API via PEAR2 |
| Router health | ⚠️ Partial | Status field, no health checks |
| Configuration sync | ❌ Missing | Not implemented |
| PPPoE Automation | ✅ Done | Create/update/disable users |
| Hotspot Automation | ⚠️ Partial | Voucher creation, no zone mgmt |
| Queue Management | ✅ Done | Simple queues + queue trees |

**Required Enhancements:**
```php
// app/Services/Network/MikroTikRouterAdapter.php
public function getHealth(Router $router): array {
    return [
        'cpu_load' => $this->getCpuLoad($router),
        'memory_usage' => $this->getMemoryUsage($router),
        'uptime' => $this->getUptime($router),
        'interface_status' => $this->getInterfaces($router),
    ];
}

public function syncConfiguration(Router $router): bool {
    // Push current config from DB to router
    // Compare and reconcile differences
}
```

---

### 7.2 RADIUS

**Status:** 80% Complete

| Feature | Status | Implementation |
|---------|--------|----------------|
| User authentication | ✅ Done | radcheck/radreply tables |
| Accounting | ✅ Done | radacct table + webhook |
| Sessions | ✅ Done | radius_sessions table |
| Attributes | ✅ Done | VSA support |

**Tables Implemented:**
```sql
-- FreeRADIUS schema
CREATE TABLE radcheck (id, tenant_id, username, attribute, op, value);
CREATE TABLE radreply (id, tenant_id, username, attribute, op, value);
CREATE TABLE radacct (id, tenant_id, acct_session_id, ...);
```

---

### 7.3 Network Actions

**Status:** 80% Complete

| Job | Status | Implementation |
|-----|--------|----------------|
| ProvisionServiceJob | ✅ Done | Creates RADIUS/MikroTik user |
| SuspendServiceJob | ✅ Done | Disables user |
| ResumeServiceJob | ✅ Done | Enables user |
| SyncRadiusJob | ✅ Done | Syncs users to RADIUS |

---

## 8. IPAM

**Status:** Missing

### 8.1 Required Features

| Feature | Implementation |
|---------|----------------|
| IPv4 pools | Pool management per tenant |
| IPv6 pools | Not prioritized (Kenyan market) |
| Subnets | CIDR-based subnet management |
| Reservations | Static IP reservations |
| Allocations | Auto/manual IP assignment |
| IP Tracking | History of assignments |

### 8.2 Database Schema

```sql
CREATE TABLE ip_pools (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100),
    subnet VARCHAR(50), -- 192.168.10.0/24
    type ENUM('pppoe','static','hotspot','cgnat'),
    router_id BIGINT UNSIGNED NULL,
    gateway VARCHAR(50),
    dns_primary VARCHAR(50),
    dns_secondary VARCHAR(50),
    total_ips INT,
    allocated_ips INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE ip_allocations (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    pool_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(50) UNIQUE,
    client_account_id BIGINT UNSIGNED NULL,
    lease_type ENUM('static','dynamic'),
    allocated_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    mac_address VARCHAR(20) NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (pool_id) REFERENCES ip_pools(id)
);

CREATE TABLE ip_allocation_history (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(50),
    client_account_id BIGINT UNSIGNED,
    allocated_at TIMESTAMP,
    released_at TIMESTAMP NULL
);
```

---

## 9. Network Monitoring

**Status:** Partial

### 9.1 Device Monitoring

| Device | Status | Protocol |
|--------|--------|----------|
| Routers | ✅ Done | RouterOS API + SNMP |
| Switches | ⚠️ Partial | SNMP only |
| OLTs | ❌ Missing | Not implemented |
| APs | ❌ Missing | Not implemented |

### 9.2 Metrics

| Metric | Status | Implementation |
|--------|--------|----------------|
| CPU | ✅ Done | SNMP/RouterOS |
| Memory | ✅ Done | SNMP/RouterOS |
| Temperature | ❌ Missing | Not implemented |
| Traffic | ⚠️ Partial | Polling stub exists |
| Uptime | ✅ Done | last_seen_at field |

### 9.3 Alerts

| Alert | Status |
|-------|--------|
| Device offline | ✅ Done |
| High bandwidth | ❌ Missing |
| Interface down | ❌ Missing |

### 9.4 Network Map

**Status:** Missing — Frontend visualization not implemented

---

## 10. Fiber / OLT Management

**Status:** Not Implemented

### 10.1 Required Features

| Feature | Implementation |
|---------|----------------|
| OLT support (Huawei, ZTE, FiberHome, VSOL) | SNMP/SSH adapters |
| PON port management | Port status, ONU count |
| ONU registration | Auto-discovery + provisioning |
| Signal monitoring | ONU optical levels |
| Fiber infrastructure | Routes, splitters, cabinets |

---

## 11. Inventory

**Status:** 85% Complete

### 11.1 Assets

| Asset Type | Status | Tracking |
|------------|--------|----------|
| Routers | ✅ Done | Serial, MAC, assignment |
| ONTs | ✅ Done | Serial, MAC, customer |
| Switches | ✅ Done | Asset tracking |
| Cables | ⚠️ Partial | Basic item tracking |
| Equipment | ✅ Done | General equipment |

### 11.2 Warehouse

| Feature | Status | Implementation |
|---------|--------|----------------|
| Stock management | ✅ Done | Quantity tracking |
| Transfers | ⚠️ Partial | Movement logging exists |
| Suppliers | ✅ Done | Supplier CRUD |

### 11.3 Serial Tracking

| Feature | Status | Implementation |
|---------|--------|----------------|
| Warranty tracking | ✅ Done | warranty_expires date |
| Assignment history | ✅ Done | Movement logs |
| Client assignment | ✅ Done | client_id on items |

---

## 12. Field Operations

**Status:** Missing

### 12.1 Work Orders

| Type | Implementation |
|------|----------------|
| Installation | Create, assign, schedule, complete |
| Repair | Troubleshooting workflow |
| Upgrade | Plan change with visit |
| Relocation | Service move |

**Schema:**
```sql
CREATE TABLE work_orders (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    type ENUM('installation','repair','upgrade','relocation'),
    client_id BIGINT UNSIGNED,
    assigned_to BIGINT UNSIGNED,
    status ENUM('pending','assigned','in_progress','completed','cancelled'),
    priority ENUM('low','medium','high','urgent'),
    scheduled_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
```

### 12.2 Technician Management

| Feature | Implementation |
|---------|----------------|
| Assignment | Work order assignment |
| Schedules | Calendar view (frontend) |
| Workload | Work order count per tech |

### 12.3 Mobile Technician Support

| Feature | Implementation |
|---------|----------------|
| GPS check-in | Location tracking on completion |
| Photos | Image upload to work order |
| Signatures | Digital signature capture |
| Offline mode | PWA with sync |

---

## 13. Customer Portal

**Status:** 60% Complete

### Frontend Requirements

```
Portal Routes:
- /portal/dashboard — Overview, balance, active services
- /portal/invoices — List + detail + pay
- /portal/payments — History
- /portal/usage — Data usage charts
- /portal/tickets — Create + view tickets
- /portal/documents — Download contracts/receipts
- /portal/notifications — In-app notifications
- /portal/profile — Edit profile, change password
```

**Mobile-Responsive Design:** Tailwind CSS, dark mode support

---

## 14. Mobile APIs

**Status:** Missing

### 14.1 Customer App (PWA)

| Feature | Endpoint |
|---------|----------|
| View invoices | GET /api/portal/invoices |
| Pay bills | POST /api/portal/invoices/{id}/pay |
| Support tickets | CRUD /api/portal/tickets |
| View usage | GET /api/portal/usage |
| Notifications | GET /api/portal/notifications |

### 14.2 Technician App (PWA)

| Feature | Endpoint |
|---------|----------|
| Work orders | GET /api/technician/work-orders |
| Navigation | GPS coordinates in response |
| Photos | POST /api/technician/work-orders/{id}/photos |
| Completion | POST /api/technician/work-orders/{id}/complete |

---

## 15. Analytics

**Status:** 85% Complete

### 15.1 Metrics

| Category | Metrics | Status |
|----------|---------|--------|
| Customers | Count, growth, churn | ✅ Done |
| Revenue | MRR, ARPU, LTV | ⚠️ Partial | LTV missing |
| Network | Bandwidth, utilization, outages | ⚠️ Partial | Needs real traffic data |
| Operations | Installs, tickets, technicians | ⚠️ Partial | Basic counts only |

### 15.2 Advanced Analytics

| Metric | Implementation |
|---------|----------------|
| ARPU | Revenue / active clients |
| Churn rate | Churned clients / total clients |
| LTV | Average revenue × average lifespan |
| Cohort analysis | Group by signup month |

---

## 16. Reporting

**Status:** 85% Complete

### 16.1 Report Types

| Report | Status | Export |
|--------|--------|--------|
| Finance: Revenue | ✅ Done | CSV |
| Finance: Expenses | ✅ Done | CSV |
| Finance: Invoices | ✅ Done | CSV |
| Finance: Payments | ✅ Done | CSV |
| Network: Usage | ⚠️ Partial | CSV |
| Network: Sessions | ⚠️ Partial | CSV |
| Network: Bandwidth | ⚠️ Partial | CSV |
| Customers: Growth | ✅ Done | CSV |
| Customers: Churn | ✅ Done | CSV |
| Customers: Retention | ❌ Missing | Not implemented |

**Missing Exports:** Excel (XLSX), PDF

### 16.2 Async Report Generation

```php
// POST /api/v1/admin/reports/generate
{
    "type": "income",
    "start": "2026-01-01",
    "end": "2026-05-31",
    "format": "xlsx"
}

// Response
{
    "job_id": "uuid",
    "status": "queued"
}

// GET /api/v1/admin/reports/status/{job_id}
{
    "status": "completed",
    "download_url": "https://storage..."
}
```

---

## 17. Integrations

### 17.1 Payments

| Gateway | Status | Implementation |
|---------|--------|----------------|
| M-Pesa | ✅ Done | STK + C2B |
| Airtel Money | ❌ Missing | Not implemented |
| Stripe | ❌ Missing | Not implemented |
| Banks | ❌ Missing | Manual only |

### 17.2 Communication

| Provider | Status | Channel |
|----------|--------|---------|
| Africa's Talking | ✅ Done | SMS + WhatsApp |
| WhatsApp | ✅ Done | Africa's Talking |
| Email | ⚠️ Partial | SMTP configured |
| Twilio | ❌ Missing | Not configured |
| SMPP | ❌ Missing | Not configured |

### 17.3 Network

| Integration | Status | Implementation |
|-------------|--------|----------------|
| MikroTik | ✅ Done | RouterOS API |
| FreeRADIUS | ✅ Done | DB-based auth + REST sync |
| SNMP | ⚠️ Partial | Read-only polling |

---

## 18. Security

### 18.1 Requirements

| Control | Status | Implementation |
|---------|--------|----------------|
| Encryption at rest | ✅ Done | Crypt::encrypt for sensitive fields |
| Secrets management | ⚠️ Partial | .env for platform, tenant in DB (encrypted) |
| Audit logs | ✅ Done | audit_logs table + AuditService |
| Rate limiting | ⚠️ Partial | Auth limited, exports not limited |
| API security | ✅ Done | Sanctum + throttle middleware |
| MFA | ⚠️ Partial | Ready, not enforced |
| Backup strategy | ❌ Missing | Not configured |
| Penetration testing | ❌ Missing | Not performed |
| Vulnerability scanning | ❌ Missing | Not configured |

### 18.2 M-Pesa Security (Critical)

**Current Issue:** HMAC validation is optional.

**Required Fix:**
```php
// app/Http/Middleware/ValidateMpesaCallback.php
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

---

## 19. Testing

### 19.1 Backend Tests

| Type | Status | Coverage |
|------|--------|----------|
| Unit tests | ⚠️ Partial | ~30% |
| Feature tests | ⚠️ Partial | ~40% |
| API tests | ⚠️ Partial | Key endpoints |

### 19.2 Required Test Suites

```php
// tests/Feature/TenantIsolationTest.php
public function test_cross_tenant_access_prevented(): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $clientA = Client::factory()->for($tenantA)->create();
    
    app()->instance('tenant', $tenantB);
    
    $this->assertNull(Client::find($clientA->id));
}

// tests/Feature/MpesaCallbackTest.php
public function test_mpesa_callback_idempotent(): void {
    // Process same callback twice, verify no duplicate payment
}

// tests/Feature/BillingTest.php
public function test_bulk_invoice_generation(): void {
    // Generate 1000 invoices, verify no N+1
}
```

### 19.3 Infrastructure Tests

| Type | Status | Tool |
|------|--------|------|
| Load testing | ❌ Missing | k6 or Artillery |
| Security testing | ❌ Missing | OWASP ZAP |
| CI/CD | ✅ Done | GitHub Actions |

---

## 20. Documentation

### 20.1 Required Documentation

| Document | Status | Location |
|----------|--------|----------|
| API docs | ⚠️ Partial | OpenAPI/Swagger needed |
| Installation guide | ✅ Done | README.md |
| Admin guide | ❌ Missing | Not written |
| Network guide | ⚠️ Partial | Architecture doc exists |
| Developer guide | ❌ Missing | Not written |

### 20.2 Code Documentation

- [ ] PHPDoc blocks on all public methods
- [ ] README per service directory
- [ ] Architecture decision records (ADRs)
- [ ] Deployment runbooks

---

## 21. Deployment

### 21.1 Production Infrastructure

```
Load Balancer (Nginx/Caddy)
├── App Server 1 (Laravel — PHP-FPM, 4 CPU, 8GB RAM)
├── App Server 2 (Laravel — PHP-FPM, 4 CPU, 8GB RAM)
└── Queue Worker (Horizon, 4 CPU, 8GB RAM)

Database: MySQL 8 (Primary + 1 Read Replica)
Cache/Queue: Redis Cluster (2 nodes)
Storage: S3 or DigitalOcean Spaces
Monitoring: Sentry + Horizon Dashboard
```

### 21.2 Docker Setup

```dockerfile
# Dockerfile
FROM php:8.3-fpm-alpine
RUN docker-php-ext-install pdo_mysql bcmath gmp
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts
COPY . .
RUN php artisan config:cache
RUN php artisan route:cache
```

### 21.3 CI/CD Pipeline

```yaml
# .github/workflows/deploy.yml
- name: Run migrations
  run: php artisan migrate --force
- name: Seed database
  run: php artisan db:seed --force
- name: Clear cache
  run: php artisan optimize:clear
- name: Restart Horizon
  run: php artisan horizon:terminate
```

### 21.4 Environment Management

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.primebill.co.ke

DB_CONNECTION=mysql
DB_HOST=prod-db.primebill.co.ke
DB_DATABASE=primebill
DB_USERNAME=primebill
DB_PASSWORD=${DB_PASSWORD}

REDIS_HOST=prod-redis.primebill.co.ke
QUEUE_CONNECTION=redis
HORIZON_ENABLED=true

FILESYSTEM_DISK=s3
AWS_BUCKET=primebill-prod

SENTRY_LARAVEL_DSN=${SENTRY_DSN}
```

---

## 22. Production Readiness Checklist

### 22.1 Performance

- [ ] Database composite indexes on all tenant-scoped tables
- [ ] Dashboard caching with tenant tags (10-min TTL)
- [ ] Replace N+1 queries with eager loading
- [ ] Use cursor pagination for large collections
- [ ] Enable MySQL query cache for read-heavy endpoints
- [ ] Configure Redis for cache + queues + sessions

### 22.2 Reliability

- [ ] Database backups (automated daily, 30-day retention)
- [ ] Read replica for reports and dashboards
- [ ] Queue workers with auto-restart (Supervisor/Horizon)
- [ ] Health check endpoint (`/health`)
- [ ] Failover configuration for critical services
- [ ] Monitoring (Sentry + Horizon + custom alerts)

### 22.3 Security

- [ ] M-Pesa HMAC validation enforced (not optional)
- [ ] Rate limiting on all public endpoints
- [ ] Rate limiting on export endpoints (prevent data exfiltration)
- [ ] Database-level pessimistic locking for payment reconciliation
- [ ] Encrypted storage for router credentials
- [ ] Tenant isolation tests passing
- [ ] CORS configured for known origins only
- [ ] Security headers (CSP, HSTS, X-Frame-Options)

### 22.4 Operations

- [ ] Structured logging (JSON format)
- [ ] Log aggregation (Papertrail/ELK)
- [ ] Alerting rules configured
- [ ] Maintenance mode implemented
- [ ] Zero-downtime deployment strategy
- [ ] Database migration testing in staging

### 22.5 Business

- [ ] Subscription management functional
- [ ] Tenant onboarding wizard
- [ ] Support ticket workflows
- [ ] Dunning workflow active
- [ ] FUP enforcement active
- [ ] Invoice templates per tenant
- [ ] PDF generation for invoices/receipts

---

## Appendix A: Module Completeness Matrix

| Module | Status | Production Ready | Priority |
|--------|--------|------------------|----------|
| Multi-Tenancy | 90% | ❌ Needs isolation tests | P1 |
| Authentication | 95% | ⚠️ MFA not enforced | P1 |
| Subscription Management | 20% | ❌ Broken | P1 |
| Billing Engine | 90% | ❌ PDFs missing | P1 |
| Payment Processing | 90% | ❌ Refunds missing | P1 |
| M-Pesa Integration | 90% | ❌ HMAC optional | P1 |
| Network Provisioning | 80% | ❌ Traffic polling empty | P1 |
| FreeRADIUS | 80% | ⚠️ Tests missing | P2 |
| SMS/Notifications | 90% | ⚠️ Email incomplete | P1 |
| Email | 85% | ❌ Mailables missing | P1 |
| Reporting | 85% | ❌ PDF/Excel export | P2 |
| Audit Logs | 85% | ⚠️ Indexes missing | P1 |
| Dashboard | 85% | ❌ No caching | P1 |
| CRM (Leads/Prospects) | 90% | ⚠️ Frontend only | P2 |
| Customer Management | 90% | ❌ Documents missing | P2 |
| Hotspot/Vouchers | 85% | ⚠️ Captive portal | P2 |
| IPAM | 0% | ❌ Not started | P3 |
| FUP Engine | 10% | ❌ Not started | P2 |
| Dunning | 0% | ❌ Not started | P2 |
| Wallet/Credit | 0% | ❌ Not started | P2 |
| Tax Management | 30% | ❌ Multi-rate missing | P2 |
| Agent/Commissions | 0% | ❌ Not started | P4 |
| Webhooks | 0% | ❌ Not started | P3 |
| SLA Tracking | 5% | ❌ Not started | P3 |
| KYC/Documents | 0% | ❌ Not started | P4 |
| Inventory | 85% | ⚠️ Minor gaps | P3 |
| Field Operations | 0% | ❌ Not started | P4 |
| Customer Portal | 60% | ❌ Incomplete frontend | P2 |
| Mobile APIs | 0% | ❌ Not started | P4 |
| Analytics | 85% | ⚠️ LTV missing | P3 |
| Integrations | 60% | ❌ Stripe/Pesapal | P3 |
| Testing | 70% | ❌ Coverage < 80% | P1 |

---

## Appendix B: Implementation Priority Matrix

### Phase 1 — Critical (Weeks 1-4)
**Goal:** Fix all production blockers

1. Fix Subscription model/table
2. Implement PollRouterTraffic
3. Enforce tenant isolation (global scopes + tests)
4. Fix M-Pesa HMAC validation
5. Fix StorePaymentRequest authorization
6. Add database composite indexes
7. Fix N+1 queries
8. Implement dashboard caching
9. Add PDF receipt generation (DomPDF)
10. Add email mailables (invoices, reminders)
11. Cross-tenant access prevention tests
12. Fix orphaned migrations

### Phase 2 — Core SaaS (Weeks 5-10)
**Goal:** Complete multi-tenant SaaS features

13. Complete subscription lifecycle
14. Dunning workflow
15. Wallet/credit system
16. Invoice line items + proration
17. Tax management (multi-rate)
18. Tenant onboarding flow
19. Platform tenant billing
20. White-labeling
21. RBAC per tenant
22. Refund workflow

### Phase 3 — Network (Weeks 11-16)
**Goal:** Production-ready network operations

23. IPAM module
24. FUP enforcement engine
25. Network abstraction layer
26. RADIUS accounting webhook
27. Hotspot captive portal
28. SLA tracking
29. Traffic monitoring (real implementation)

### Phase 4 — Ecosystem (Weeks 17-24)
**Goal:** Advanced features and integrations

30. Agent/commission management
31. Webhook engine
32. SMS campaign manager
33. Advanced reporting
34. KYC module
35. Customer PWA
36. API keys per tenant
37. Enhanced audit trail

---

## Appendix C: Environment Variables Reference

```dotenv
# Application
APP_NAME=PrimeBill
APP_ENV=production
APP_KEY=base64:...
APP_URL=https://app.primebill.co.ke

# Database
DB_CONNECTION=mysql
DB_HOST=prod-db.primebill.co.ke
DB_PORT=3306
DB_DATABASE=primebill
DB_USERNAME=primebill
DB_PASSWORD=${DB_PASSWORD}
DB_READ_HOST=prod-db-replica.primebill.co.ke

# Redis
REDIS_HOST=prod-redis.primebill.co.ke
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379
REDIS_CACHE_DB=0
REDIS_QUEUE_DB=1
REDIS_SESSION_DB=2

# Queue
QUEUE_CONNECTION=redis
HORIZON_ENABLED=true

# Storage
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID}
AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY}
AWS_DEFAULT_REGION=eu-west-1
AWS_BUCKET=primebill-prod

# M-Pesa
MPESA_ENVIRONMENT=production
MPESA_CONSUMER_KEY=${MPESA_CONSUMER_KEY}
MPESA_CONSUMER_SECRET=${MPESA_CONSUMER_SECRET}
MPESA_SHORTCODE=174379
MPESA_PASSKEY=${MPESA_PASSKEY}
MPESA_CALLBACK_SECRET=${MPESA_CALLBACK_SECRET}

# Email
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=${MAIL_USERNAME}
MAIL_PASSWORD=${MAIL_PASSWORD}
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@primebill.co.ke

# SMS
SMS_PROVIDER=africastalking
AFRICASTALKING_API_KEY=${AFRICASTALKING_API_KEY}
AFRICASTALKING_USERNAME=${AFRICASTALKING_USERNAME}

# Monitoring
SENTRY_LARAVEL_DSN=${SENTRY_DSN}
TELESCOPE_ENABLED=false

# Network
NETWORK_ROUTER_DRIVER=mikrotik
NETWORK_RADIUS_DRIVER=freeradius
```

---

## Appendix D: Production Launch Sequence

### Week 1: Foundation Hardening
- [ ] Day 1-2: Fix all P1 security issues (HMAC, auth, tenant isolation)
- [ ] Day 3-4: Add composite indexes, fix N+1, add caching
- [ ] Day 5: Run full test suite, fix failures

### Week 2: Billing Completeness
- [ ] Day 1-2: Implement PDF generation
- [ ] Day 3-4: Add email mailables
- [ ] Day 5: End-to-end billing flow test

### Week 3: Network Reliability
- [ ] Day 1-2: Implement PollRouterTraffic
- [ ] Day 3-4: Add circuit breakers for provisioning
- [ ] Day 5: Load test provisioning jobs

### Week 4: Final Validation
- [ ] Day 1: Security scan
- [ ] Day 2: Performance test
- [ ] Day 3: Backup/restore drill
- [ ] Day 4: Documentation review
- [ ] Day 5: Production launch

---

*This blueprint is maintained alongside `PRIMEBILL_ARCHITECTURE.md`, `IMPLEMENTATION_ROADMAP.md`, and `PROJECT_STATUS.md`. Update this document when phases are completed.*
