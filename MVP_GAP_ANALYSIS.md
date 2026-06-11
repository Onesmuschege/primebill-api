# MVP_GAP_ANALYSIS.md

This document compares the current repository state (Onesmuschege/primebill-api) against the ISP Billing MVP checklist provided.

Status key
- Present — feature appears implemented and routes/services/controllers exist.
- Partial — feature scaffolded or partly implemented; needs verification, tests, or missing sub-features.
- Missing — not implemented or no clear scaffold found.

SUMMARY
- Overall: The codebase is feature-rich and already covers most core ISP Billing functionality. Several items are present but need verification, tests, hardening, or missing small sub-features to be fully MVP-ready.

1) CUSTOMER MANAGEMENT
- Customer registration: Missing
  - Reason: I found clients endpoints for staff/admin create (POST /api/clients) but no public self-registration endpoint or registration workflow for customers in the portal routes.
  - Remediation: Add portal self-registration endpoint with email/phone verification (or expose a controlled registration flow). Add tests and rate limiting.
  - Est: 1 day.

- Customer profiles: Present
  - Reason: Client and portal profile controllers exist; model Client present per README and routes.
  - Remediation: Verify profile fields, privacy, and update flows; add tests.
  - Est: 0.5 day for verification + tests.

- Service status: Partial
  - Reason: Client suspend/activate endpoints exist; network session monitoring exists but API surface for per-service status (active/suspended/terminated) needs verification.
  - Remediation: Add explicit service status endpoints (if missing) and ensure UI/portal visibility. Tie router/RADIUS state to service status through service adapters.
  - Est: 1 day.

- Customer search: Partial
  - Reason: clients index exists, but search/filter capabilities must be verified (query params, pagination).
  - Remediation: Ensure index supports search by name/email/account, add tests and indexes for performance.
  - Est: 0.5–1 day.

- Customer suspension/activation: Present
  - Reason: routes /api/clients/{client}/suspend and /activate exist and protected by permissions.
  - Remediation: Ensure these actions trigger provisioning jobs (router/RADIUS) and audit logs; add tests and failure handling.
  - Est: 0.5 day.

2) SERVICE PLANS
- Internet packages: Present
  - Reason: Plan model & PlanSeeder present; plans endpoints exist.

- Bandwidth profiles: Present
  - Reason: Plans have speed_up and speed_down fields; FUP/burst referenced in README.

- Pricing plans: Present
  - Reason: price field in PlanSeeder and endpoints.

- Plan assignment: Partial
  - Reason: The PlanController exposes clients listing per plan; but the action to assign a plan to a client (and the provisioning flow) needs confirmation.
  - Remediation: Add/verify assign-plan endpoint & provisioning job that updates RouterOS and RADIUS profiles; add tests.
  - Est: 1 day.

3) BILLING
- Billing cycles: Present (scheduled jobs)
  - Reason: README lists scheduled bills and console commands; invoice bulk generation endpoint exists.

- Invoice generation: Present
  - Reason: InvoiceController and bulk-generate endpoint exist; Project has GenerateMonthlyInvoices command referenced.

- Invoice status: Partial
  - Reason: Invoicing engine exists, but fields for status (paid/unpaid/overdue/cancelled) must be validated across models and API responses.
  - Remediation: Ensure consistent status enums, add API filters and tests.
  - Est: 0.5 day.

- Tax support: Missing
  - Reason: No explicit tax configuration or invoice tax lines detected in the sampled files or README.
  - Remediation: Add tax fields to invoice line items and settings for tax rates (multiple jurisdictions optional), update PDF template and calculations, add tests.
  - Est: 1–2 days.

- Recurring billing: Present (via scheduled commands)
  - Reason: Scheduled job for monthly generation is documented.

4) PAYMENTS
- Payment recording: Present
  - Reason: PaymentController and Payment model present; MPesa integration present.

- Payment reconciliation: Partial
  - Reason: MpesaController and ProcessMpesaPayment job exist; need to validate robust reconciliation (matching STK/c2b to invoices, handling duplicates and reconciliation status).
  - Remediation: Add/review reconciliation logic, idempotency handling, and tests (including simulated MPesa callback payloads).
  - Est: 1–2 days.

- Outstanding balances: Partial
  - Reason: Payment summary endpoints exist; ensure per-client balance calculation is present and accounts for credits/adjustments.
  - Remediation: Add APIs and tests for outstanding balances and statement generation.
  - Est: 1 day.

- Receipt generation: Partial
  - Reason: README references PDF export via DomPDF; ensure receipts (per payment) are generated and downloadable.
  - Remediation: Add receipt generation endpoints and tests.
  - Est: 0.5–1 day.

5) NETWORK PROVISIONING
- Service activation/suspension/termination: Partial
  - Reason: client suspend/activate endpoints exist; RouterController and MikroTikService exist. Need to verify automated provisioning calls occur on suspend/activate and on new account creation.
  - Remediation: Implement explicit provisioning worker jobs (create user on RouterOS, create RADIUS user) and write tests using mock adapters.
  - Est: 2–4 days (depending on router/RADIUS complexity).

- Router integration abstraction: Partial
  - Reason: evilfreelancer/routeros-api-php is required and MikroTikService referenced. Need adapter interface and mock implementation for testing and local dev.
  - Remediation: Define RouterAdapterInterface, implement RealRouterAdapter and MockRouterAdapter; add unit tests.
  - Est: 1–2 days.

- Radius integration abstraction: Partial
  - Reason: FreeRADIUS sync is referenced but adapter implementation confirmation needed.
  - Remediation: Add RadiusAdapterInterface and a mock implementation; create a SyncRadiusUsers command with tests.
  - Est: 1–2 days.

6) AUTHENTICATION & AUTHORIZATION
- Login: Present (AuthController)
- Password reset: Missing
  - Reason: changePassword exists, but no password reset (email token) flow found.
  - Remediation: Add password reset endpoints using Laravel's password broker (email reset), add tests and email templates.
  - Est: 0.5–1 day.

- Roles & Permissions: Present (Spatie)
  - Reason: RolesAndPermissionsSeeder and middleware usage in routes.

7) NOTIFICATIONS
- Email notifications: Partial
  - Reason: Not explicitly referenced in sampled files; queued jobs present (likely used for PDF/SMS). Email templates/actions need verification.
  - Remediation: Add or confirm Mailable classes for invoices, receipts, and reminders; add tests with mail fakes.
  - Est: 0.5–1 day.

- SMS notifications: Present
  - Reason: SmsController and gateway adapters exist; SendSmsJob and SendBulkSmsJob present.
  - Remediation: Add test fakes for gateways and ensure retries/logging.
  - Est: 0.5 day.

- Payment reminders & Invoice notifications: Partial
  - Reason: Scheduled jobs are referenced in README for reminders; verify implementation and testing.
  - Remediation: Ensure scheduled SendInvoiceReminders uses SMS/email notifications appropriately and expose configuration for schedules.
  - Est: 0.5–1 day.

8) SUPPORT
- Ticket creation: Present
  - Reason: TicketController routes exist for create, reply, assign, close.

- Ticket tracking & resolution workflow: Partial
  - Reason: Basic operations present; SLA/escalation policies and automation need verification/implementation.
  - Remediation: Implement escalation rules and notifications; add tests.
  - Est: 1–2 days.

9) REPORTING
- Revenue reports: Present
  - Reason: ReportController includes income and clients/invoices endpoints.

- Customer reports: Present
- Outstanding invoice reports: Present (reports/invoices)
- Subscription reports: Partial
  - Reason: analytics endpoints exist but may need extended filters and tests.
  - Remediation: Add parameterized report filters and export options.
  - Est: 1 day.

10) SYSTEM SETTINGS
- Company profile: Partial
  - Reason: SettingsController exists; verify UI and settings persistence.
  - Remediation: Add settings validation and seed default settings; tests.
  - Est: 0.5 day.

- Tax configuration: Missing (see Billing tax support)
- Billing configuration: Partial
  - Reason: scheduler settings referenced but centralized billing configuration may be missing (grace periods, overdue thresholds configurable in settings?).
  - Remediation: Add settings keys for billing rules and UI.
  - Est: 0.5 day.

- Notification configuration: Partial
  - Reason: config/sms.php exists and config/mpesa.php; ensure all gateways and email settings are centralized in settings and .env example.
  - Est: 0.5 day.

11) AUDIT & LOGGING
- User activity logs: Present
  - Reason: SystemLog model and LogController exist; AuthController writes login/logout logs.

- Billing logs: Partial
  - Reason: FUP, invoices, payments likely recorded but ensure BillingService writes audit entries for invoice generation and adjustments.
  - Remediation: Add billing audit events if missing and tests for idempotency.
  - Est: 0.5–1 day.

- Payment logs: Partial
  - Reason: MPesa logs and Sms logs exist; ensure reconciliation events are logged.
  - Est: 0.5 day.

NON-FUNCTIONAL, INFRA & OPS
- Tests: Partial / Unknown
  - Reason: phpunit present in composer dev dependencies; tests directory needs enumeration and baseline run.
  - Remediation: Run PHPUnit, add missing unit & feature tests for critical flows, and add coverage reporting in CI.
  - Est: Initial baseline 1–2 days.

- CI: Missing/Unknown
  - Reason: No .github/workflows found in initial sample; ensure CI is present and runs phpunit and static analysis on pushes.
  - Remediation: Add GitHub Actions workflow for phpunit, static analysis, and PHP CS Fixer if missing.
  - Est: 0.5–1 day.

- Static analysis / typing: Missing/Partial
  - Reason: PHPStan / Larastan not present in composer.json; add for quality gates.
  - Remediation: Add larastan as dev dependency and fix issues progressively.
  - Est: 1–3 days depending on baselining.

- Security hardening: Partial
  - Reason: config/mpesa.php includes callback hardening options (allowed IPs & signature secret) but enforcement middleware was not yet identified in code samples.
  - Remediation: Implement MPesa callback verification middleware, ensure CORS and rate limiting, remove default credentials from README, and verify proper storage of secrets.
  - Est: 1 day.

PRIORITIZATION (recommended)
1. MPesa callback verification & robust payment reconciliation (Critical) — 1–2 days.
2. Remove default credentials and secure README + .env.example (Critical) — 0.5 day.
3. Tests for authentication, invoice generation, payment reconciliation, provisioning (High) — 2–4 days.
4. RouterOS & RADIUS mock adapters and provisioning tests (High) — 2–4 days.
5. Add CI (phpunit & static analysis) (High) — 0.5–1 day.
6. Implement password reset, tax fields, and receipts (Medium) — 2–4 days.

OUTSTANDING UNKNOWN AREAS
- The exact list of migrations and their contents (I will enumerate and validate in the next step).
- Presence and contents of tests/ directory and the baseline test pass rate.
- Whether MPesa callback verification is already implemented as middleware or inside MpesaController.

NEXT STEPS (immediate)
1. Enumerate migrations and run static analysis & unit tests to capture failing tests and TODOs.
2. Implement MPesa callback verification middleware and add idempotent reconciliation logic & tests.
3. Redact default credentials in README and update .env.example.
4. Add RouterOS & RADIUS mock adapters and write provisioning tests.
5. Commit MVP_GAP_ANALYSIS.md and start implementing the prioritized items.

Prepared by: GitHub Copilot (as senior software architect)
Date: 2026-06-11
