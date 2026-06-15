# MVP Gap Analysis — Updated Status

**Last reviewed:** 2026-06-15  
**Repository:** primebill-api (Laravel 12)

This document tracks MVP checklist items against the current codebase.

Status key: **Present** | **Partial** | **Missing**

---

## SUMMARY

The codebase now covers the core ISP billing MVP. Network provisioning (RouterOS + RADIUS) is wired through adapter interfaces with mock drivers for dev/test and real drivers for production. Remaining gaps are mostly operational hardening (PDF receipts, email templates, broader test coverage, PHPStan).

---

## 1) CUSTOMER MANAGEMENT

| Item | Status | Notes |
|---|---|---|
| Customer registration | **Present** | `POST /api/portal/register` with rate limiting |
| Customer profiles | **Present** | Portal + admin client endpoints |
| Service status | **Present** | `GET /api/clients/{client}/accounts/{account}/status` |
| Customer search | **Present** | `search` query param on clients index |
| Suspension/activation | **Present** | DB + network jobs on suspend/activate |

---

## 2) SERVICE PLANS

| Item | Status | Notes |
|---|---|---|
| Internet packages | **Present** | Plans CRUD with speed/FUP fields |
| Bandwidth profiles | **Present** | speed_up/down, burst, FUP on plans |
| Pricing plans | **Present** | price field + endpoints |
| Plan assignment | **Present** | `POST /api/plans/{plan}/assign` + provisioning job |

---

## 3) BILLING

| Item | Status | Notes |
|---|---|---|
| Billing cycles | **Present** | Scheduled commands in `routes/console.php` |
| Invoice generation | **Present** | Manual + bulk + monthly command |
| Invoice status | **Present** | unpaid/paid/overdue/partial/cancelled + filters |
| Tax support | **Present** | Auto-apply `tax_rate` from settings via `InvoiceService::calculateTax()` |
| Recurring billing | **Present** | `billing:generate-invoices` scheduled monthly |

---

## 4) PAYMENTS

| Item | Status | Notes |
|---|---|---|
| Payment recording | **Present** | PaymentController + M-Pesa STK/C2B |
| Payment reconciliation | **Present** | `payments:reconcile-mpesa` + idempotency |
| Outstanding balances | **Present** | `GET /api/clients/{id}/balance`, portal balance |
| Receipt generation | **Partial** | JSON receipt at `GET /api/payments/{id}/receipt` (PDF not yet added) |

---

## 5) NETWORK PROVISIONING

| Item | Status | Notes |
|---|---|---|
| Service activation/suspension | **Present** | Jobs: Provision, Suspend, Activate, Deprovision |
| RouterOS abstraction | **Present** | `RouterAdapterInterface`, `MikroTikRouterAdapter`, `MockRouterAdapter` |
| RADIUS abstraction | **Present** | `RadiusAdapterInterface`, `FreeRadiusAdapter`, `MockRadiusAdapter` |
| Traffic polling | **Present** | `network:poll-traffic` command (scheduled every 5 min) |
| RADIUS sync | **Present** | `radius:sync-users` command + `POST /api/radius/sync` |
| RADIUS accounting | **Present** | `POST /api/webhooks/radius/accounting` + `ProcessRadiusAccountingJob` |

**Production config:** Set `NETWORK_ROUTER_DRIVER=mikrotik` and `NETWORK_RADIUS_DRIVER=freeradius` in `.env`.

---

## 6) AUTHENTICATION & AUTHORIZATION

| Item | Status | Notes |
|---|---|---|
| Login | **Present** | Admin + portal |
| Password reset | **Present** | `/api/auth/password/forgot` and `/reset` |
| Roles & permissions | **Present** | Spatie permission middleware |

---

## 7) NOTIFICATIONS

| Item | Status | Notes |
|---|---|---|
| Email notifications | **Partial** | Mail config present; invoice/receipt mailables not yet added |
| SMS notifications | **Present** | SmsService + gateways + jobs |
| Payment/invoice reminders | **Present** | `billing:send-reminders` scheduled |

---

## 8) SUPPORT

| Item | Status | Notes |
|---|---|---|
| Ticket creation | **Present** | Full ticket CRUD + portal |
| SLA/escalation | **Partial** | Escalate endpoint exists; SLA policies not configured |

---

## 9) REPORTING

| Item | Status | Notes |
|---|---|---|
| Revenue/customer/invoice reports | **Present** | ReportController |
| Subscription reports | **Partial** | Analytics endpoints; extended filters optional |

---

## 10) SYSTEM SETTINGS

| Item | Status | Notes |
|---|---|---|
| Company profile | **Present** | SettingsController + seeder |
| Tax configuration | **Present** | `tax_rate` in settings, auto-applied on invoices |
| Billing configuration | **Present** | grace_period, auto_suspend, auto_invoice in settings |
| Notification configuration | **Partial** | SMS/M-Pesa in settings; email gateway settings minimal |

---

## 11) AUDIT & LOGGING

| Item | Status | Notes |
|---|---|---|
| User activity logs | **Present** | SystemLog + LogController |
| Billing logs | **Present** | InvoiceService writes audit entries |
| Network provisioning logs | **Present** | `mikrotik_sync_logs` via ProvisioningService |
| Payment logs | **Present** | M-Pesa callbacks + payment failures tables |

---

## NON-FUNCTIONAL

| Item | Status | Notes |
|---|---|---|
| Tests | **Partial** | Mpesa, provisioning, portal register, password reset, tax, client API |
| CI | **Present** | `.github/workflows/ci.yml` runs migrations + tests |
| Static analysis | **Missing** | PHPStan/Larastan not yet added |
| Security hardening | **Partial** | M-Pesa callback middleware present; rotate default seeds |

---

## REMAINING POST-MVP ITEMS

1. PDF receipts/invoices (DomPDF or similar)
2. Email mailables for invoices, receipts, reminders
3. PHPStan/Larastan baseline
4. Hotspot voucher/prepaid module (architecture doc outlines this)
5. FUP enforcement engine (schema exists; enforcement logic pending)
6. Broader C2B callback test coverage

---

## NEW FILES (2026-06-15 MVP completion)

- `config/network.php` — adapter driver configuration
- `app/Services/Network/MikroTikRouterAdapter.php`
- `app/Services/Network/ProvisioningService.php`
- `app/Services/Radius/FreeRadiusAdapter.php`
- `app/Jobs/ProvisionClientAccountJob.php` (+ Suspend, Activate, Deprovision, ProcessRadiusAccounting)
- `app/Http/Controllers/Portal/PortalRegisterController.php`
- `app/Http/Controllers/Api/RadiusController.php`
- `app/Http/Controllers/Api/RadiusAccountingController.php`
- `app/Services/Billing/BalanceService.php`
- `database/migrations/2026_06_15_100000_create_freeradius_tables.php`
