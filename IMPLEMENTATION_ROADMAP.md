# IMPLEMENTATION_ROADMAP.md

This roadmap prioritizes work required to reach production readiness for PrimeBill. It is derived from the codebase analysis and commit history.

Priority 1 — Critical (0–2 weeks)
- Add/expand tests for: MPesa callbacks (idempotency), billing reconciliation, tenancy isolation, billing scheduled jobs (suspend/reactivate), router provisioning.
- Integrate activity/audit logging (spatie/laravel-activitylog or OwenIt auditing) for User, Client, Invoice, Payment.
- Add CI workflow to run migrations and full test suite on PRs.
- Harden MPesa callback verification (HMAC/IP allowlist) and DB locking around payment reconciliation.

Priority 2 — High (2–6 weeks)
- Tenant isolation tests and TenantContext middleware + BelongsToTenant trait (if not fully present).
- Add bulk export jobs (queue-based) + storage (S3) for large reports.
- Add Sentry / job monitoring and queue health alerts.
- Implement audit & retention policies for traffic/radius logs.

Priority 3 — Medium (1–3 months)
- Dashboard backend endpoints for Super Admin (aggregations & streaming endpoints).
- Rate-limiting & circuit-breakers for provisioning adapters.
- IPAM & FUP enforcement engines.

Priority 4 — Long-term (3–6 months)
- Billing engine improvements: proration, credit notes, invoice previews & approval, tenant invoice sequences.
- Platform tenant billing & SaaS metering.
- Integrations: Pesapal/Flutterwave/Stripe, Webhook delivery engine, TR-069 ACS integration (optional).

Each item should include acceptance criteria, tests, and documentation updates.
