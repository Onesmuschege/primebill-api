# PrimeBill OSS/BSS Production Blueprint v1.0 — Master Implementation Plan

**Date:** May 2026  
**Status:** Planning Phase  
**Target:** Full production-ready ISP billing platform

---

## Executive Summary

This document consolidates all analysis from `PRIMEBILL_ARCHITECTURE.md`, `PROJECT_STATUS.md`, and `IMPLEMENTATION_ROADMAP.md` into a single actionable master plan. It prioritizes work to transform PrimeBill from its current partial implementation into a production-ready SaaS multi-tenant ISP billing platform.

**Current State:** 70-95% complete across modules, but missing critical multi-tenancy, dunning, FUP, IPAM, and several core billing features.

**Strategy:** Phased delivery with Foundation Fixes (P1) → Core SaaS (P2) → Network (P3) → Ecosystem (P4).

---

## Phase 1 — Foundation Fixes (Weeks 1-4) 🔴 CRITICAL

**Goal:** Fix critical bugs, security vulnerabilities, and establish multi-tenancy foundation.

### 1.1 Multi-Tenancy Core
- [ ] **Tenant Model & Migration**
  - Create `tenants` table with slug, custom_domain, plan, status, settings, branding
  - Create `tenant_limits` table with quotas
  - Create `tenant_domains` table for custom domain mapping
  - **Files:** `database/migrations/2026_01_create_tenants_tables.php`

- [ ] **BelongsToTenant Trait**
  - Global scope filtering by `tenant_id`
  - Auto-set `tenant_id` on model creation
  - **Files:** `app/Models/Traits/BelongsToTenant.php`

- [ ] **TenantResolver Middleware**
  - Resolve tenant from subdomain, custom domain, or API header
  - Set tenant context in container
  - **Files:** `app/Http/Middleware/ResolveTenant.php`

- [ ] **Tenant Isolation Tests**
  - Cross-tenant access prevention test
  - Verify global scope on all tenant models
  - **Files:** `tests/Feature/TenantIsolationTest.php`

### 1.2 Critical Bug Fixes
- [ ] **Fix SubscriptionService**
  - Create `Subscription` model with full lifecycle
  - Implement auto-renewal, proration, upgrades/downgrades
  - **Files:** `app/Models/Subscription.php`, `app/Services/Billing/SubscriptionService.php`

- [ ] **Fix PollRouterTrafficJob**
  - Implement real MikroTik interface polling
  - Store traffic metrics in `network_traffic` table
  - **Files:** `app/Jobs/PollRouterTrafficJob.php`

- [ ] **Fix StorePaymentRequest::authorize()**
  - Add real permission check: `$this->user()->can('record-payments')`
  - Add tenant check: `$this->user()->tenant_id === app('tenant')->id`
  - **Files:** `app/Http/Requests/Payment/StorePaymentRequest.php`

- [ ] **Fix Orphaned Migration**
  - Remove references to `mpesa_callbacks`, `payment_failures` tables
  - **Files:** `database/migrations/2026_04_25_134600_*`

### 1.3 Security Hardening
- [ ] **M-Pesa HMAC Validation**
  - Make HMAC validation mandatory (abort 500 if secret not set)
  - Remove optional bypass
  - **Files:** `app/Http/Middleware/ValidateMpesaCallback.php`

- [ ] **M-Pesa Race Condition Fix**
  - Use DB-level pessimistic locking in STK callback
  - `MpesaTransaction::lockForUpdate()->where('checkout_request_id', ...)->first()`
  - **Files:** `app/Services/Mpesa/MpesaService.php`

- [ ] **Rate Limiting on Exports**
  - Apply `throttle:exports` to all export endpoints
  - **Files:** `routes/api.php`

### 1.4 Performance Fixes
- [ ] **Fix N+1 Queries**
  - `InvoiceService::bulkGenerate()` — eager load clients
  - `DashboardService::getTopDownloaders()` — eager load account->client
  - `ReportService::getIncomeReport()` — use DB aggregation instead of PHP grouping
  - **Files:** `app/Services/Billing/InvoiceService.php`, `app/Services/Dashboard/DashboardService.php`, `app/Services/Reporting/ReportService.php`

- [ ] **Add Missing Database Indexes**
  - Composite indexes on `invoices(tenant_id, status, due_date)`
  - Composite indexes on `payments(tenant_id, client_id, created_at)`
  - Composite indexes on `client_accounts(tenant_id, status, expiry_date)`
  - **Files:** New migration `2026_02_add_missing_indexes.php`

- [ ] **Dashboard Caching**
  - Cache dashboard stats per tenant with 10-min TTL
  - Tag caches for invalidation on data changes
  - **Files:** `app/Http/Controllers/Api/DashboardController.php`

- [ ] **Replace Synchronous SMS Loops**
  - Dispatch `SendSmsJob` instead of sending inline
  - **Files:** All controllers/services sending SMS

### 1.5 API Standardization
- [ ] **ApiResponse Trait**
  - Create standardized response envelope
  - Apply to all controllers
  - **Files:** `app/Http/Traits/ApiResponse.php`

**Deliverables:**
- All P0 security fixes deployed
- Multi-tenant isolation fully functional
- Test suite passes with tenant isolation tests
- Performance benchmarks met (< 200ms p95 API response)

---

## Phase 2 — Core SaaS Features (Weeks 5-10) 🟠 HIGH

**Goal:** Complete subscription management, dunning, wallet, and tenant onboarding.

### 2.1 Subscription Management (Complete Rebuild)
- [ ] **Subscription Model & Migration**
  - Status: active, pending, suspended, cancelled, expired
  - Billing cycle: monthly, quarterly, annual, custom
  - Auto-renewal flag, trial support
  - **Files:** `database/migrations/2026_03_create_subscriptions_table.php`, `app/Models/Subscription.php`

- [ ] **Subscription Service**
  - Create, update, cancel, pause subscriptions
  - Proration calculation for mid-cycle changes
  - Auto-renewal job dispatch
  - **Files:** `app/Services/Billing/SubscriptionService.php`

- [ ] **Subscription Controller**
  - CRUD endpoints for subscriptions
  - Change plan, change cycle, cancel
  - **Files:** `app/Http/Controllers/Api/SubscriptionController.php`

### 2.2 Dunning Management
- [ ] **Dunning Policy Model**
  - Configurable steps with grace periods
  - Actions: notify, restrict_speed, suspend, terminate
  - **Files:** `app/Models/DunningPolicy.php`, `app/Models/DunningNotice.php`

- [ ] **Dunning Engine Job**
  - Run every 6 hours
  - Find overdue invoices, apply policy steps
  - **Files:** `app/Jobs/RunDunningEngineJob.php`

- [ ] **Dunning Controller**
  - CRUD policies, view notices
  - **Files:** `app/Http/Controllers/Api/DunningController.php`

### 2.3 Wallet & Credit System
- [ ] **Wallet Model & Migration**
  - Balance tracking, currency support
  - **Files:** `database/migrations/2026_04_create_wallets_table.php`, `app/Models/Wallet.php`

- [ ] **Wallet Transaction Service**
  - Credit, debit, refund, adjustment
  - Auto-apply credit to new invoices
  - **Files:** `app/Services/Billing/WalletService.php`

- [ ] **Wallet Controller**
  - View balance, transaction history
  - Manual adjustment (admin only)
  - **Files:** `app/Http/Controllers/Api/WalletController.php`

### 2.4 Invoice Enhancements
- [ ] **Invoice Line Items**
  - Proration, addons, one-off charges
  - **Files:** `app/Models/InvoiceLineItem.php`

- [ ] **Tax Management**
  - VAT 16%, configurable rates
  - Compound tax support
  - **Files:** `app/Models/TaxRate.php`, `app/Services/Billing/TaxService.php`

- [ ] **Credit Notes & Debit Notes**
  - Issue, apply to invoices
  - **Files:** `app/Models/CreditNote.php`, `app/Models/DebitNote.php`

### 2.5 Tenant Onboarding
- [ ] **Tenant Signup Flow**
  - Public registration endpoint
  - Create tenant + admin user
  - Seed default settings, plans, roles
  - **Files:** `app/Http/Controllers/Api/TenantSignupController.php`

- [ ] **Setup Wizard**
  - Branding, email/SMS config, payment gateway
  - **Files:** Frontend: `primebill-frontend/src/pages/onboarding/`

### 2.6 Platform Tenant Billing (SaaS Metering)
- [ ] **Platform Plans & Subscriptions**
  - Meter client count, SMS usage, API calls
  - Generate platform invoices
  - **Files:** `app/Models/PlatformPlan.php`, `app/Models/TenantSubscription.php`

### 2.7 White-Labeling
- [ ] **Branding Settings**
  - Per-tenant logo, colors, company name
  - Inject into invoices, emails, portal
  - **Files:** `app/Services/BrandingService.php`

### 2.8 RBAC per Tenant
- [ ] **Role Management**
  - Tenant admin can create custom roles
  - Assign permissions granularly
  - **Files:** `app/Http/Controllers/Api/RoleController.php` (tenant-scoped)

**Deliverables:**
- Subscription lifecycle fully functional
- Dunning workflow operational
- Wallet system deployed
- New tenant onboarding automated
- Platform billing for SaaS tenants

---

## Phase 3 — Network & Provisioning (Weeks 11-16) 🟡 MEDIUM

**Goal:** Complete IPAM, FUP, RADIUS accounting, hotspot/vouchers, SLA.

### 3.1 IPAM (IP Address Management)
- [ ] **IP Pool Model & Migration**
  - Subnet, gateway, DNS, type (pppoe/static/hotspot/cgnat)
  - **Files:** `app/Models/IpPool.php`

- [ ] **IP Allocation Model**
  - Track assignments, leases, history
  - Auto-allocate next free IP
  - **Files:** `app/Models/IpAllocation.php`, `app/Models/IpAllocationHistory.php`

- [ ] **IPAM Service**
  - Allocate, release, reserve IPs
  - Subnet utilization reports
  - **Files:** `app/Services/Network/IpamService.php`

- [ ] **IPAM Controller**
  - CRUD pools, view allocations
  - **Files:** `app/Http/Controllers/Api/IpPoolController.php`

### 3.2 FUP Engine
- [ ] **FUP Tier Model**
  - Data thresholds, bandwidth limits per tier
  - **Files:** `app/Models/PlanFupTier.php`

- [ ] **Usage Cycle Model**
  - Track bytes up/down, current tier
  - **Files:** `app/Models/ClientUsageCycle.php`

- [ ] **FUP Sync Job**
  - Poll RADIUS/MikroTik every 15 minutes
  - Update usage, detect tier changes
  - Send CoA packets
  - **Files:** `app/Jobs/SyncFupUsageJob.php`

- [ ] **FUP Controller**
  - View usage, configure tiers
  - **Files:** `app/Http/Controllers/Api/FupController.php`

### 3.3 Network Abstraction Layer
- [ ] **NetworkProvisionerInterface**
  - createUser, disableUser, enableUser, updateBandwidth, sendCoA
  - **Files:** `app/Interfaces/NetworkProvisionerInterface.php`

- [ ] **MikroTikProvisioner**
  - Implement interface using existing MikroTikService
  - **Files:** `app/Services/Network/MikroTikProvisioner.php`

- [ ] **RadiusProvisioner**
  - Implement interface using FreeRADIUS
  - **Files:** `app/Services/Network/RadiusProvisioner.php`

### 3.4 RADIUS Accounting
- [ ] **Radius Session Model**
  - Track sessions, bytes, timestamps
  - **Files:** `app/Models/RadiusSession.php`

- [ ] **RADIUS Accounting Webhook**
  - Receive interim/stop packets
  - Store raw payload, dispatch processing job
  - **Files:** `app/Http/Controllers/Api/WebhookController.php` (radius endpoint)

### 3.5 Hotspot & Voucher System
- [ ] **Hotspot Zone Model**
  - Router, profile, walled garden
  - **Files:** `app/Models/HotspotZone.php`

- [ ] **Voucher Batch & Voucher Models**
  - Generate batches, track status
  - **Files:** `app/Models/VoucherBatch.php`, `app/Models/Voucher.php`

- [ ] **Voucher Service**
  - Generate codes, activate, expire
  - MikroTik hotspot user creation
  - **Files:** `app/Services/Network/VoucherService.php`

- [ ] **Voucher Controller**
  - CRUD batches, view vouchers
  - **Files:** `app/Http/Controllers/Api/VoucherController.php`

### 3.6 SLA Tracking
- [ ] **SLA Policy Model**
  - Uptime target, response time, compensation
  - **Files:** `app/Models/SlaPolicy.php`

- [ ] **Service Incident Model**
  - Outage, degraded, maintenance events
  - **Files:** `app/Models/ServiceIncident.php`

- [ ] **SLA Report Job**
  - Monthly SLA calculation
  - **Files:** `app/Jobs/GenerateSlaReportJob.php`

**Deliverables:**
- IPAM fully operational with auto-allocation
- FUP engine enforcing bandwidth tiers
- RADIUS accounting integrated
- Hotspot/voucher system live
- SLA tracking and reporting

---

## Phase 4 — Ecosystem & Growth (Weeks 17-24) 🟢 LOW

**Goal:** Agent commissions, webhooks, advanced reporting, KYC, mobile PWA.

### 4.1 Agent & Commission Management
- [ ] Agent model, commission tracking
- [ ] Agent portal endpoints
- [ ] Payout management

### 4.2 Webhook Engine
- [ ] Webhook endpoint model, event subscriptions
- [ ] Delivery job with retry/backoff
- [ ] HMAC signing

### 4.3 SMS Campaign Manager
- [ ] Campaign model, segmentation
- [ ] Scheduled sending
- [ ] Template management

### 4.4 Advanced Reporting
- [ ] AR aging report
- [ ] FUP usage report
- [ ] RADIUS session report
- [ ] Dunning effectiveness report
- [ ] Revenue forecast

### 4.5 KYC Module
- [ ] Client document uploads
- [ ] Verification workflow
- [ ] Expiry tracking

### 4.6 Client Mobile PWA
- [ ] Push notifications
- [ ] Self-service payments
- [ ] Usage monitoring

### 4.7 API Keys
- [ ] Per-tenant API keys
- [ ] Scoped permissions
- [ ] Usage tracking

### 4.8 Audit Trail Enhancement
- [ ] Apply to all models via observers
- [ ] Field-level change tracking
- [ ] Retention policies

**Deliverables:**
- Agent/reseller system operational
- Webhook notifications live
- Advanced reports available
- KYC workflow functional
- Mobile PWA deployed

---

## Implementation Guidelines

### Code Standards
- All models use `BelongsToTenant` trait
- All controllers use `ApiResponse` trait
- All business logic in service classes
- All jobs should be idempotent
- All endpoints versioned `/api/v1/`

### Testing Requirements
- Unit tests for all services
- Feature tests for all endpoints
- Tenant isolation tests for all models
- Integration tests for payment flow
- Load tests for reporting endpoints

### Documentation
- API docs via OpenAPI/Swagger
- Database schema diagrams
- Deployment guides
- Network integration guides
- Developer onboarding

### Deployment Checklist
- [ ] All tests passing
- [ ] Database indexes applied
- [ ] Queue workers configured
- [ ] Redis cluster setup
- [ ] S3/Spaces configured
- [ ] Monitoring (Sentry + Horizon)
- [ ] Backup strategy
- [ ] SSL certificates
- [ ] Rate limiting active
- [ ] Firewall rules

---

## Success Metrics

| Metric | Target |
|--------|--------|
| Test Coverage | > 80% |
| API Response Time (p95) | < 200ms |
| Dashboard Load Time | < 1s |
| Queue Job Success Rate | > 99.5% |
| Payment Reconciliation Accuracy | 100% |
| Cross-Tenant Data Leakage | 0 incidents |
| Uptime | 99.9% |

---

## Next Steps

1. **Review this plan** with stakeholders
2. **Prioritize Phase 1** items for immediate start
3. **Set up project board** with GitHub Projects/Jira
4. **Begin with multi-tenancy** — all other work depends on it
5. **Assign developers** to parallel workstreams where possible

---

*This plan should be reviewed and updated weekly. Each phase has clear acceptance criteria and deliverables.*
