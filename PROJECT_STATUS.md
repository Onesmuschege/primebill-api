# PROJECT_STATUS.md

This document is an authoritative, code-and-commit-driven status list for PrimeBill (backend). It reflects the repository as of the most recent commits on main.

Format: Module | Completion % | Status | Blockers | Next steps

- Authentication & RBAC | 95% | Implemented (Sanctum + Spatie) | Need permission seeds & full role matrix seeding | Add tests for role-based endpoints
- Multi-tenancy | 85% | Tenants table & tenant_id columns applied across models | Require tenancy isolation tests and CI checks | Add tenancy isolation tests; enforce tenant_id non-null where safe
- Billing & Invoicing | 90% | Invoices CRUD, bulk generation, PDF export | Reconciliation stress tests | Add more tests for bulk generation
- Payments & M-Pesa | 90% | STK + C2B + idempotency + enhanced callback verification | Add more reconciliation tests and edge-case handling | Add integration tests and monitoring
- Loyalty & Referral | 80% | LoyaltyPoints model, controller & migration | Removed orphaned LoyaltyTransaction; verify adjust flows | Add tests for earn/redeem/expiry
- Vouchers & Hotspot | 85% | Voucher model, batches, redemption endpoints | Add printable PDF voucher booklets & CPE provisioning tests | Add tests and voucher-printing job
- Network Provisioning (RouterOS) | 80% | MikroTik adapter + Mock adapter, provisioning jobs | Add circuit-breaker/resilience tests & scaling | Add integration tests with a sandbox router
- FreeRADIUS | 80% | Schema and adapters present | Add performance tests for accounting ingestion | Add retention/rollup task
- SMS & WhatsApp | 90% | SMS gateways + WhatsAppService config fixed | Verify provider credentials & opt-in flows | Add provider failover tests
- Email & Communication | 85% | Single EmailService in Communication wired to jobs | Validate templates & email deliverability | Add integration test for email sending (with mock)
- Reporting & Dashboard | 70% | API endpoints for key KPIs present | Frontend dashboard features and streaming endpoints missing | Build backend streaming / aggregated tables
- Audit & Activity Logs | 10% | Minimal SystemLog model present | Missing spatie activitylog integration | Integrate audit logs and wire model events
- Tests & CI | 60% | Some feature tests exist; composer scripts & GH workflow references | Need comprehensive test coverage and CI gating | Add/expand tests and verify workflows


---

This file is generated from the code and commit history. See IMPLEMENTATION_ROADMAP.md for prioritized tasks.
