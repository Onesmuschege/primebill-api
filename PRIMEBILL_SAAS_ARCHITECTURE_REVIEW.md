# PrimeBill Backend Architecture Review and SaaS Design Proposal

## 1. Purpose

This document reviews the current `primebill-api` Laravel backend and proposes a production-grade ISP billing system architecture for a SaaS platform using one shared multi-tenant database. The proposal is tailored to Kenyan ISP operations but avoids tenant-specific database separation.

## 2. Current Backend Snapshot

PrimeBill is currently a Laravel modular monolith with these main areas:

- API and portal routes in `routes/api.php`.
- Controllers in `app/Http/Controllers/Api` and `app/Http/Controllers/Portal`.
- Domain services under `app/Services` for billing, client management, finance, inventory, reporting, settings, SMS, support, M-Pesa, and MikroTik.
- Scheduled commands under `app/Console/Commands`.
- Core tables for clients, accounts, plans, routers, invoices, payments, ledger entries, tickets, SMS logs, network traffic, RADIUS sessions, settings, inventory, commissions, and system logs.

This is a reasonable starting point for a single-tenant ISP management product. It is not yet structured as a SaaS backend because tenant identity is absent from the data model, authorization model, cache keys, jobs, webhooks, and integrations.

## 3. Current Strengths

- Clear separation between controllers, request validation, services, and models.
- Laravel Sanctum and Spatie permissions are already present.
- Payment idempotency and M-Pesa callback tables have been started.
- Billing ledger entries exist, which is important for auditability.
- Network, SMS, reporting, ticketing, inventory, and finance domains are represented.
- Some performance indexes were added for invoices, payments, accounts, tickets, traffic, sessions, and logs.

## 4. Critical Code and Design Findings

### 4.1 Multi-Tenancy Is Missing

No `tenants`, `tenant_id`, tenant middleware, tenant-aware global scopes, tenant-aware permissions, or tenant-scoped indexes are present. In a shared database SaaS model, every tenant-owned row must be isolated by `tenant_id`. Current unique constraints such as `clients.email`, `clients.phone`, `client_accounts.username`, `invoices.invoice_number`, and `settings.key` are global, which would prevent two ISPs from using the same customer phone, PPPoE username, invoice sequence, or setting key.

### 4.2 Schema and Validation Are Inconsistent

- `StoreClientRequest` and `UpdateClientRequest` require `city`, `account_type`, and `plan_id`, but `clients` migration and `Client::$fillable` use `town` and do not include `city`, `account_type`, or `plan_id`.
- `ClientResource` references `uuid`, `city`, `account_type`, `total_invoiced`, `total_paid`, `balance`, and `ClientAccountResource`, but those fields/resource are not implemented in the current code shown.
- `PaymentService` writes invoice status `partial`, but the `invoices.status` enum only allows `draft`, `unpaid`, `paid`, `overdue`, and `cancelled`.
- `LedgerService::postInvoiceReversal()` writes `entry_type = invoice_reversal`, but the `ledger_entries.entry_type` enum does not allow it.
- Soft delete columns were added to clients, invoices, and payments, but the corresponding models do not use Laravel `SoftDeletes`.

### 4.3 Incomplete or Broken Modules

- `SubscriptionService` references `App\Models\Subscription` and events such as `SubscriptionCreated`, but no matching model, migration, or events were found.
- `SyncRadiusUsers` and `PollRouterTraffic` commands are placeholders.
- `GenerateMonthlyInvoices` generates invoices for every active account without checking cycle boundaries or existing invoices, so it can duplicate billing.
- Tests use Pest-style `beforeEach()` and `test()` functions, but the project dependency list shows PHPUnit and no Pest dependency. Tests also call `Client::factory()` and `Plan::factory()`, but only `UserFactory.php` exists.
- `MpesaService::handleC2BConfirmation()` references `Client::where(...)` without importing `App\Models\Client`.

### 4.4 Security and Data Protection Gaps

- Router passwords are hidden from JSON but appear to be stored directly, not encrypted at rest.
- Client portal tokens are issued from the `Client` model, while login credentials are stored on `ClientAccount`; this can work, but the security boundary is unclear when one client has multiple accounts.
- M-Pesa and SMS settings exist both in config/env and in the `settings` table, but services mostly read config values. This creates split-brain configuration.
- System logs store snapshots that may include sensitive values unless redaction is enforced centrally.

### 4.5 Performance and Scale Limitations

- Many reports load full result sets into memory and aggregate in PHP, for example income, invoice, SMS, network, and expenditure reports in `ReportService`.
- Dashboard statistics issue many independent count/sum queries. This will become slow in pooled SaaS unless tenant-scoped summary tables or cached aggregates are introduced.
- Client detail endpoints return all invoices, payments, tickets, and accounts with `get()` instead of pagination.
- `GenerateMonthlyInvoices`, `SuspendOverdueAccounts`, and reminder commands load all qualifying rows into memory rather than chunking.
- Traffic and RADIUS tables will grow quickly and need retention, partitioning or rollups, and tenant-scoped time indexes.
- Search uses `%term%` patterns across names, phones, emails, and invoice numbers. At scale this needs normalized search columns or a search index.
- Database queue is acceptable for early MVP, but SaaS production should use Redis/SQS-style queues with tenant-aware job payloads, retries, dead-letter handling, and queue isolation for high-volume SMS/network work.

## 5. Target Architecture Principles

The target should remain a modular monolith initially, with strong module boundaries and asynchronous workers. This keeps operational complexity low while allowing later extraction of high-volume modules such as rating, notification delivery, RADIUS sync, or analytics.

Core principles:

- One shared database, pooled tenant data, explicit `tenant_id` on every tenant-owned table.
- Tenant isolation enforced at middleware, model global scope, validation rules, policies, queues, cache, filesystem, and webhook resolution.
- Financial records are append-only where possible. Corrections should use reversals and adjustments, not destructive updates.
- Network provisioning must be event-driven and retryable.
- Billing must be cycle-aware, idempotent, and auditable.
- Operational data such as sessions, traffic, logs, and notifications must have retention and rollup policies.

## 6. Proposed SaaS Runtime Architecture

```text
Frontend SPA / Tenant Portal
        |
Laravel API Modular Monolith
        |
Tenant Resolver Middleware
        |
Domain Modules: CRM, Billing, Payments, AAA, Provisioning, Support, Inventory, Reporting
        |
Shared MySQL/PostgreSQL Database with tenant_id
        |
Redis Cache + Queue Broker
        |
Workers: billing, payments, sms, provisioning, radius, reporting
        |
External Integrations: M-Pesa, SMS gateways, MikroTik, FreeRADIUS, Email, Object Storage
```

## 7. Shared Multi-Tenant Database Design

### 7.1 Tenant Control Plane

Add central SaaS tables:

- `tenants`: ISP organization record, legal name, slug, status, timezone, currency, billing status.
- `tenant_domains`: custom domains and subdomains.
- `tenant_plans`: SaaS subscription tier, limits, enabled modules.
- `tenant_users`: users linked to tenants if a user can access multiple ISPs.
- `tenant_settings`: tenant-scoped settings; replaces global `settings.key`.
- `tenant_audit_events`: immutable SaaS and tenant security audit stream.

### 7.2 Tenant-Owned Tables

Add `tenant_id` to all operational tables:

- `clients`, `client_accounts`, `plans`, `routers`, `invoices`, `payments`, `ledger_entries`, `tickets`, `ticket_replies`, `sms_logs`, `settings`, `inventory_items`, `radius_sessions`, `network_traffic`, `mpesa_transactions`, `notifications`, `system_logs`, `sales_commissions`, `expenditures`, `fup_logs`.

Use composite uniqueness:

- `unique(tenant_id, clients.phone)`
- `unique(tenant_id, clients.email)`
- `unique(tenant_id, client_accounts.username)`
- `unique(tenant_id, invoices.invoice_number)`
- `unique(tenant_id, settings.key)`
- `unique(tenant_id, payments.mpesa_code)` where supported or with nullable-safe alternatives.

Use tenant-leading indexes for common access paths:

- `(tenant_id, status, created_at)`
- `(tenant_id, client_id, status)`
- `(tenant_id, router_id, recorded_at)`
- `(tenant_id, username, status)`
- `(tenant_id, invoice_id, status)`

### 7.3 Tenant Resolution

Resolve tenant from one of:

- Subdomain: `tenant.primebill.app`.
- Custom domain in `tenant_domains`.
- Admin-selected tenant for platform staff.
- Webhook payload mapping, such as M-Pesa shortcode/paybill, callback URL path, account reference prefix, or configured tenant integration key.

Create a `TenantContext` service and `BelongsToTenant` model trait. The trait should apply a global scope, auto-fill `tenant_id` on create, and prevent cross-tenant relations.

### 7.4 Tenant-Aware Jobs and Events

Every queued job must carry `tenant_id` and initialize `TenantContext` before executing. This applies to SMS delivery, billing cycles, payment reconciliation, RADIUS sync, router provisioning, invoice generation, and report exports.

### 7.5 Cache and Storage Isolation

Cache keys must include tenant identity, for example `tenant:{id}:settings:{key}`. File storage paths should use `tenants/{tenant_id}/...` for logos, invoices, exports, and attachments.

## 8. Proposed Domain Modules

### 8.1 SaaS Administration

Required for platform operation:

- Tenant onboarding, suspension, reactivation, offboarding.
- Tenant subscription and platform billing.
- Module entitlements and usage limits.
- Platform admin impersonation with audited access.
- Tenant health dashboard.

### 8.2 Identity, Access, and Roles

Current roles are global. Convert permissions to tenant-aware access:

- Platform roles: owner, support, finance, operations.
- Tenant roles: ISP owner, admin, accountant, support, network engineer, field technician, sales agent.
- Client portal roles and account-level access.
- MFA support for staff and tenant owners.
- API tokens scoped by tenant, ability, and expiry.

### 8.3 CRM and Subscriber Lifecycle

Extend client management into a full subscriber lifecycle:

- Leads, prospects, customers, and inactive customers.
- KYC documents and ID verification metadata.
- Service address, GPS, installation address, billing address.
- Multiple services/accounts per customer.
- Customer status transitions with reason codes.
- Notes, attachments, consent records, and communication preferences.

### 8.4 Product Catalog, Pricing, and Rating

Plans should become catalog products:

- Plan catalog with service type: PPPoE, hotspot, static IP, dedicated link, enterprise, voice, value-added service.
- Price books per tenant, currency, tax profile, branch, or customer segment.
- Recurring, one-time, installation, deposit, and penalty charges.
- Promotions, discounts, coupons, bundles, and add-ons.
- FUP rules, throttling profiles, burst settings, night bundles, data caps, and validity windows.
- Versioned plan changes so historical invoices remain accurate.

### 8.5 Order and Work Order Management

Current client/account creation jumps directly to active service. A production ISP needs:

- Sales order.
- Feasibility check.
- Installation work order.
- Technician assignment and scheduling.
- Equipment reservation.
- Activation approval.
- Cancellation and downgrade/upgrade workflows.
- SLA timers and installation status.

### 8.6 Billing Engine

Replace simple monthly invoice generation with a billing engine:

- Billing accounts separate from customer profiles.
- Billing cycles per tenant/customer/account.
- Proration for mid-cycle activation, upgrade, downgrade, suspension, and cancellation.
- Invoice runs with preview, approval, posting, and rollback-by-reversal.
- Invoice line items, taxes, discounts, penalties, credits, and adjustments.
- Dunning schedules and grace periods.
- Promise-to-pay and payment plans.
- Revenue recognition metadata.
- Per-tenant invoice sequences.

### 8.7 Payments, Wallet, and Reconciliation

Current payment handling should evolve into:

- Payment intents and asynchronous provider callbacks.
- M-Pesa STK, C2B, Paybill/Till, bank deposits, cards, cash, wallet credits.
- Overpayment handling, customer wallet, unapplied cash, refunds, and reversals.
- Bank/M-Pesa statement import and reconciliation.
- Idempotent provider events with immutable raw payloads.
- Fraud and duplicate detection.
- Tenant-specific payment credentials and callback routing.

### 8.8 Ledger and Accounting

The ledger should be expanded:

- Append-only double-entry or at least balanced subledger entries.
- Accounts receivable, cash, tax liability, discounts, bad debt, write-offs, refunds.
- Reversal entries instead of deleting financial effects.
- Period close and export to accounting systems.
- Tenant-level chart of accounts mapping.

### 8.9 AAA, RADIUS, and Session Accounting

Current `radius_sessions` is not enough for production AAA. Add:

- RADIUS users/check/reply/group tables or integration with FreeRADIUS schema.
- Accounting ingestion from `radacct`.
- Interim update handling for active sessions.
- Session start/stop tracking with NAS/router identity.
- Disconnect/CoA command dispatch.
- MAC binding and simultaneous-use controls.
- Data usage aggregation for FUP and usage-based billing.
- RADIUS sync logs per tenant/router/account.

### 8.10 Network Provisioning and IPAM

Provisioning should be event-driven:

- Router inventory with encrypted credentials.
- Router groups/sites/POPs.
- PPPoE secret, hotspot user, static lease, queues, profiles, address lists.
- Retryable provisioning jobs with state machine: pending, provisioning, active, failed, rollback_required.
- IP pools, static IP allocation, public/private IP management.
- VLAN, NAS, OLT/ONU/CPE references where relevant.
- Drift detection between database and routers.

### 8.11 CPE and Field Asset Management

Inventory should support real ISP operations:

- Serialized devices, routers, ONUs, antennas, power supplies, cables.
- Stock locations, warehouses, technician van stock.
- Asset assignment to customer/service/account.
- Warranty, RMA, returns, replacement, and write-off.
- Optional TR-069/USP ACS integration for CPE configuration and diagnostics.

### 8.12 Support, SLA, and Field Service

Ticketing should include:

- SLA policies by customer segment and ticket type.
- Escalation rules and breach notifications.
- Field visits, dispatch, technician mobile workflow.
- Customer-facing ticket updates and attachments.
- Outage linking so one network incident can affect many tickets.

### 8.13 Network Monitoring and Assurance

Add operational assurance:

- Router polling and interface health.
- Active subscriber count by NAS/router/POP.
- Traffic rollups by account, router, plan, and tenant.
- Outage incidents and maintenance windows.
- Alerts and notification routing.
- Capacity planning reports.

### 8.14 Notifications and Communications

SMS should become a multi-channel notification service:

- SMS, email, WhatsApp, push, in-app notifications.
- Template versioning per tenant/language.
- Delivery provider failover.
- Queue-based sending with rate limits.
- Delivery receipts and bounce handling.
- Opt-out and consent tracking.

### 8.15 Reporting, BI, and Revenue Assurance

Move heavy reporting away from raw operational scans:

- Daily/monthly tenant summary tables.
- Revenue, AR aging, collections, churn, activation, suspension, usage, outage, and technician productivity reports.
- Export jobs for large CSV/PDF reports.
- Revenue leakage checks: active accounts without invoices, paid invoices without ledger entries, router users without active billing accounts, duplicate payments, missing RADIUS accounting.

### 8.16 Integration and Public API

Expose stable tenant-scoped APIs:

- API clients and OAuth/token management.
- Webhooks for payment received, invoice issued, customer suspended, ticket created.
- OpenAPI documentation.
- Integration event log and retry UI.

## 9. Proposed Module Boundaries in Laravel

Keep the codebase as a modular monolith with explicit namespaces:

```text
app/
  Domains/
    Tenancy/
    Identity/
    CRM/
    Catalog/
    Orders/
    Billing/
    Payments/
    Ledger/
    AAA/
    Provisioning/
    NetworkMonitoring/
    Inventory/
    Support/
    Notifications/
    Reporting/
    Audit/
```

Each domain should own its models, actions/services, policies, events, jobs, and tests. Controllers should be thin and call application actions. Shared utilities should live under `app/Support`.

## 10. Key Database Additions

| Area | Proposed Tables |
|---|---|
| Tenancy | `tenants`, `tenant_domains`, `tenant_settings`, `tenant_subscriptions`, `tenant_usage_limits` |
| CRM | `customer_documents`, `customer_notes`, `customer_contacts`, `customer_addresses`, `communication_preferences` |
| Catalog | `products`, `product_prices`, `plan_versions`, `tax_profiles`, `discounts`, `promotions` |
| Orders | `orders`, `order_items`, `work_orders`, `technician_assignments`, `service_appointments` |
| Billing | `billing_accounts`, `billing_cycles`, `invoice_line_items`, `credit_notes`, `debit_notes`, `dunning_steps` |
| Payments | `payment_intents`, `payment_provider_events`, `wallet_transactions`, `refunds`, `reconciliation_batches` |
| Ledger | `ledger_accounts`, `ledger_transactions`, `ledger_lines`, `accounting_periods` |
| AAA | `radius_accounts`, `radius_accounting_records`, `radius_groups`, `nas_devices`, `coa_requests` |
| Provisioning | `provisioning_tasks`, `router_profiles`, `ip_pools`, `ip_allocations`, `service_instances` |
| Monitoring | `outages`, `alerts`, `traffic_rollups`, `router_health_snapshots`, `maintenance_windows` |
| Inventory | `stock_locations`, `asset_serials`, `asset_assignments`, `stock_movements`, `rma_cases` |
| Support | `sla_policies`, `ticket_events`, `field_visits`, `attachments`, `incident_ticket_links` |

## 11. Performance Design

Recommended design changes:

- Use tenant-leading composite indexes on all high-volume tables.
- Use cursor pagination for large logs, payments, sessions, and traffic records.
- Replace report-time PHP aggregation with SQL aggregation and summary tables.
- Chunk scheduled jobs using `chunkById()` or cursor iteration.
- Add rollup tables for daily traffic, payment totals, invoice aging, and SMS delivery.
- Partition or archive high-volume tables such as `network_traffic`, `radius_sessions`, `system_logs`, `sms_logs`, and provider callback logs.
- Use queue names by workload: `billing`, `payments`, `sms`, `provisioning`, `radius`, `reports`.
- Add job uniqueness for billing runs, invoice generation, callbacks, and provisioning tasks.
- Cache tenant settings, permissions, product catalog, and dashboard summaries using tenant-scoped cache keys.

## 12. Security Design

Required controls:

- Encrypt router passwords, payment credentials, SMS credentials, API tokens, and provider secrets.
- Redact secrets in logs and audit snapshots.
- Add tenant-aware authorization policies to every model.
- Require MFA for tenant admins and platform admins.
- Use signed webhook routes or tenant-specific callback secrets where supported.
- Store raw payment callbacks immutably.
- Add audit events for login, impersonation, billing changes, payment changes, credential updates, role changes, and tenant suspension.
- Separate platform admin access from tenant staff access.

## 13. Migration Plan

### Phase 1: Stabilize Current Backend

- Align migrations, validation, resources, and model fillables.
- Add missing enums: `partial` invoice status and `invoice_reversal` ledger type, or refactor statuses into lookup/config tables.
- Add `SoftDeletes` traits where `deleted_at` exists.
- Remove or complete broken `SubscriptionService`.
- Implement `SyncRadiusUsers` and `PollRouterTraffic` or remove scheduled references.
- Fix `MpesaService` imports and add callback tests.
- Convert tests fully to PHPUnit or add Pest properly; add missing factories.

### Phase 2: Introduce Tenancy Foundation

- Create `tenants` and tenant settings.
- Add nullable `tenant_id` to tenant-owned tables.
- Backfill a default tenant for existing data.
- Add `TenantContext`, tenant middleware, tenant-aware validation rules, tenant scopes, and tenant-leading indexes.
- Convert global unique constraints to composite tenant constraints.
- Update jobs, commands, and callbacks to resolve tenant context.

### Phase 3: Billing and Payment Hardening

- Add invoice line items, billing cycles, billing accounts, payment intents, provider events, wallet transactions, and reconciliation batches.
- Make ledger append-only and reversal-based.
- Add invoice run preview and approval.
- Add dunning workflows and notification schedules.

### Phase 4: ISP Operations Modules

- Build order/work order flow.
- Add RADIUS accounting ingestion and CoA/disconnect support.
- Add provisioning task state machine.
- Add IPAM and CPE asset lifecycle.
- Add traffic rollups, outage management, and alerting.

### Phase 5: SaaS Operations

- Add tenant onboarding, tenant subscription billing, usage limits, module entitlements, platform admin console, support impersonation, and tenant health dashboards.

## 14. Immediate Engineering Priorities

1. Fix schema/request mismatches for clients.
2. Fix billing enum mismatches for `partial` and `invoice_reversal`.
3. Add tests that can actually run in the current dependency stack.
4. Add tenant design before new feature expansion; otherwise every module will require expensive rework.
5. Make all payment and invoice changes transactionally safe and idempotent.
6. Encrypt integration credentials before production use.
7. Replace report memory aggregation with SQL aggregates and exports queued as jobs.

## 15. Research Notes and References

The architecture aligns with these industry and platform references:

- TM Forum Open Digital Architecture and Open APIs for telecom-style OSS/BSS decomposition and interoperability: https://www.tmforum.org/open-digital-architecture/
- Microsoft Azure SaaS tenancy patterns, including multi-tenant application patterns with shared database models: https://learn.microsoft.com/en-gb/azure/azure-sql/database/saas-tenancy-app-design-patterns
- MikroTik RouterOS RADIUS documentation for PPP/Hotspot integration and AAA behavior: https://help.mikrotik.com/docs/spaces/ROS/pages/328097/RADIUS
- FreeRADIUS SQL accounting/data usage documentation for usage and session accounting concepts: https://www.freeradius.org/documentation/freeradius-server/4.0~alpha1/howto/modules/sql/data-usage-reporting.html
- Splynx ISP billing platform feature scope for billing, payments, networking, support, inventory, and customer portal expectations: https://splynx.com/

