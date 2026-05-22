# PrimeBill — Full Architecture Migration Orchestration
## Master Blueprint: Single-Tenant Monolith → SaaS Multi-Tenant Platform

> **Classification:** Principal Engineering Artifact  
> **Risk Level:** HIGH — Production migration of billing-critical system  
> **Strategy:** Phased Strangler + Anti-Corruption Layer  
> **Total Estimated Phases:** 4 (24 weeks)

---

## SECTION 1 — CURRENT VS TARGET GAP ANALYSIS

### 1.1 Master Comparison Table

| Area | Current State | Target State | Migration Risk | Priority | Strategy |
|---|---|---|---|---|---|
| **Database** | Single-tenant, no tenant_id, no isolation | Shared DB, row-level isolation, tenant_id on all tables | CRITICAL | P0 | Additive column + global scopes |
| **Architecture** | Laravel 11 monolith, no domain separation | Layered monolith with domain modules, contracts | LOW | P2 | Incremental extraction |
| **Multi-Tenancy** | None — single ISP only | Full tenant lifecycle, middleware, scopes | CRITICAL | P0 | Trait + middleware injection |
| **Auth** | Sanctum + Spatie, no tenant scope | Same + tenant resolution layer + platform admin | HIGH | P0 | Middleware wrapping |
| **Subscription Service** | Broken — references non-existent model | Full lifecycle: create, renew, upgrade, cancel | CRITICAL | P0 | Full rebuild with new model |
| **Billing Engine** | Basic monthly invoices, no line items | Line items, proration, tax, credit notes, templates | HIGH | P1 | Additive schema + service extension |
| **Payments** | M-Pesa + manual only, race condition | Multi-gateway, wallet, refunds, idempotent | HIGH | P1 | Fix race condition first, then extend |
| **M-Pesa Security** | HMAC optional (HIGH vuln) | HMAC mandatory, abort(500) if unconfigured | CRITICAL | P0 | Single-file fix |
| **FUP Engine** | Fields exist, zero logic | Full CoA-based throttling, cycle tracking | HIGH | P2 | New service + jobs |
| **Dunning** | Binary suspend, no grace | Configurable policy, escalating steps | HIGH | P1 | New module entirely |
| **IPAM** | Ad-hoc, no tracking | Pool management, auto-alloc, history | MEDIUM | P2 | New tables + service |
| **Hotspot/Vouchers** | Missing | Zone + batch + MikroTik integration | MEDIUM | P3 | New module |
| **Wallet/Credit** | Missing | Per-client balance, auto-apply, transactions | HIGH | P1 | New tables + payment hook |
| **Tax** | Missing | VAT 16%, WHT, configurable rates | MEDIUM | P1 | New table + invoice hook |
| **Agents/Commissions** | Referenced but no model | Full agent CRUD, commission calc, payouts | MEDIUM | P3 | New module |
| **Webhooks** | Missing | Configurable endpoints, signed delivery, retry | LOW | P3 | New module |
| **RADIUS** | Partial accounting | Full accounting webhook, session tracking | MEDIUM | P2 | Extend existing |
| **Notifications** | SMS only (Africa's Talking, Hostpinnacle) | Multi-channel: SMS, email, WhatsApp, in-app | MEDIUM | P1 | Laravel Notification refactor |
| **Caching** | Dashboard recalcs on every request | Tagged cache per tenant, 10-min TTL | HIGH | P0 | Cache::tags() wrapping |
| **Queues** | Basic, no priority separation | Horizon with 8 named priority queues | MEDIUM | P1 | Horizon install + queue config |
| **Jobs** | 3 jobs (SendSms, SendBulkSms, ProcessMpesa) | 16+ jobs across 8 queues | MEDIUM | P1 | Incremental job creation |
| **Scheduled Commands** | 4 commands (2 stubs) | 13 commands, all `.onOneServer()` | HIGH | P0 | Fix stubs, add missing |
| **PollRouterTraffic** | Empty handle() — non-functional | Real MikroTik interface polling | CRITICAL | P0 | Implement from spec |
| **N+1 Queries** | 3 confirmed N+1 locations | All eager-loaded, DB aggregation | HIGH | P0 | Targeted fix per location |
| **Rate Limiting** | Missing on exports | Per-tenant, per-route limits via RateLimiter | HIGH | P0 | Route group middleware |
| **API Response Format** | Inconsistent across controllers | Single ApiResponse trait, envelope format | MEDIUM | P1 | Trait + controller refactor |
| **API Versioning** | No versioning (/api/*) | /api/v1/, deprecation headers | LOW | P2 | Route prefix change |
| **Audit Trail** | system_logs, no indexes, no field-level diff | audit_logs, JSON old/new values, indexed | MEDIUM | P1 | New table + observer |
| **KYC/Documents** | Missing | Document upload, verification workflow | LOW | P3 | New module |
| **SLA Tracking** | Missing | Incident log, uptime calc, monthly reports | LOW | P3 | New module |
| **Platform Billing** | Missing | Stripe/Pesapal for charging tenants | HIGH | P2 | New module |
| **White-labeling** | Missing | Per-tenant logo, colors, invoice templates | MEDIUM | P2 | Tenant settings + templates |
| **RBAC per Tenant** | Global Spatie roles only | Tenant-scoped roles, tenant admin manages staff | HIGH | P1 | Spatie team config |
| **Reporting** | Partial, in-memory grouping | DB aggregation, async generation, S3 export | HIGH | P1 | ReportService rewrite |
| **Infrastructure** | Single server implied | Load balanced, MySQL + read replica, Redis cluster | MEDIUM | P3 | Docker + infra-as-code |
| **Secrets Management** | Mix of .env and DB | Platform secrets in .env, tenant secrets encrypted in DB | HIGH | P0 | Encryption migration |
| **Password Minimum** | 6 characters | 8 characters minimum | LOW | P0 | Single validation change |
| **StorePaymentRequest** | authorize() returns true | Real permission + tenant check | CRITICAL | P0 | Single-file fix |

### 1.2 Blocking Issues (Must Fix Before Any SaaS Work)

These BLOCK the entire migration. Nothing else can proceed safely:

1. **No Subscription model** — `SubscriptionService` is completely non-functional. Every billing flow that depends on subscriptions is broken.
2. **No tenant_id anywhere** — Adding multi-tenancy later will require backfilling millions of rows. Must be the first schema change.
3. **M-Pesa HMAC optional** — Any public actor can forge payment callbacks. Security breach risk.
4. **PollRouterTraffic empty** — FUP engine cannot be built on top of a stub.
5. **StorePaymentRequest::authorize() = true** — Any authenticated user can record arbitrary payments.

### 1.3 Dead Code & Incomplete Modules

```
DEAD CODE:
- app/Services/Billing/SubscriptionService.php (entire file — references App\Models\Subscription which doesn't exist)
- app/Console/Commands/PollRouterTraffic.php::handle() (empty method body)

INCOMPLETE MODULES:
- Finance/CommissionService.php — commission model missing
- FUP fields on plans table — no enforcement engine
- Dunning — binary cron only

DANGEROUS REFACTORS (sequence-critical):
- Adding tenant_id must happen BEFORE any new feature tables
- Global scopes must be added AFTER tenant_id backfill
- SubscriptionService rebuild must happen BEFORE dunning engine
- IPAM must happen BEFORE hotspot (shares IP pool concept)
- Wallet BEFORE agent commissions (commission payouts use wallet)
```

### 1.4 Hidden Coupling Risks

```
COUPLING RISK 1: Setting::where('key', ...) scattered across 8+ files
  → Any settings schema change breaks all callers silently
  → Fix: SettingsService::get() wrapper before any schema change

COUPLING RISK 2: MikroTikService called directly from controllers
  → Switching router driver breaks controllers not services
  → Fix: NetworkProvisionerInterface abstraction in Phase 3

COUPLING RISK 3: Dashboard stats in DashboardController (no service)
  → Caching applied to controller = cache never invalidated cross-controller
  → Fix: DashboardService with proper cache tags in Phase 0

COUPLING RISK 4: Spatie roles are global (no team context)
  → Role "admin" for tenant A = role "admin" for tenant B
  → Fix: Enable Spatie teams in Phase 1, before any new tenant onboarding

COUPLING RISK 5: M-Pesa credentials in config() / .env
  → Multi-tenant requires per-tenant M-Pesa credentials
  → Fix: tenant_settings encrypted JSON in Phase 1
```

---

## SECTION 2 — SAFE MIGRATION STRATEGY

### 2.1 Chosen Strategy: Phased Strangler Fig + Anti-Corruption Layer

**Decision:** NOT big bang. NOT parallel architecture. 

**Rationale:**
- Existing system is production billing software. Downtime = lost revenue for ISPs.
- Codebase is a Laravel monolith with service layer — the structure is salvageable.
- Most bugs are omissions (missing models, empty methods) not wrong design.
- The strangler pattern lets us add tenant context incrementally without breaking existing flows.

**Pattern Description:**
1. Wrap existing functionality with tenant context (anti-corruption layer)
2. New modules built tenant-aware from day 1
3. Old non-tenant flows deprecated only after verified replacement
4. Feature flags gate new behaviors until verified stable

### 2.2 Branch Strategy

```
main                    ← production, protected, requires 2 approvals
├── develop             ← integration branch
│   ├── architecture/multi-tenant-foundation   ← Phase 0 branch
│   ├── feature/subscription-model             ← P0 critical fix
│   ├── feature/tenant-models                  ← Phase 1
│   ├── feature/dunning-engine                 ← Phase 2
│   └── feature/fup-engine                     ← Phase 2
└── hotfix/*            ← emergency production fixes only
```

### 2.3 Release Strategy

- **Phase 0 fixes** deploy via hotfix PRs — small, reviewable, targeted
- **Phase 1-4** deploy via feature branches merged to develop, then staged release
- Semantic versioning: `v1.x.x` = current, `v2.0.0` = first multi-tenant release
- Every phase gets a git tag: `v2.0.0-phase0`, `v2.0.0-phase1`, etc.

### 2.4 Rollback Strategy

- Every database migration is reversible (`down()` fully implemented)
- Phase 0 fixes are non-destructive (additive only or single-line fixes)
- Feature flags in `tenant_features` JSON allow instant disable without deploy
- Queue jobs are idempotent — replay safe

### 2.5 Database Migration Strategy

```
Order of operations (STRICTLY ENFORCED):
1. Additive migrations only (new columns nullable, new tables)
2. Backfill migrations (populate tenant_id on existing rows)
3. Constraint migrations (add NOT NULL after backfill verified)
4. Index migrations (always online-safe in MySQL 8 InnoDB)
5. Destructive cleanup (drop old columns/tables — Phase 4 only)
```

---

## SECTION 3 — GIT & BRANCHING PLAN

### 3.1 Branch Naming Convention

```
architecture/<scope>      ← structural changes (no feature, no hotfix)
feature/<module>-<desc>   ← new functionality
refactor/<module>-<desc>  ← improving existing without behavior change  
fix/<issue>-<desc>        ← bug fixes going through develop
hotfix/<issue>-<desc>     ← emergency production fixes
migration/<phase>-<desc>  ← database migrations
```

### 3.2 Commit Convention (Conventional Commits)

```
<type>(<scope>): <description>

Types: feat, fix, refactor, perf, security, migration, test, chore, docs
Scopes: tenancy, billing, payments, mpesa, fup, dunning, ipam, auth, api, infra

Examples:
security(mpesa): make HMAC validation non-optional, abort 500 if unconfigured
migration(tenancy): add tenant_id column to all 18 tables, nullable
feat(subscriptions): create Subscription model and rebuild SubscriptionService
fix(dashboard): add 10-minute cache with tenant-tagged invalidation
perf(invoices): replace N+1 loop with eager loading in bulkGenerate
```

### 3.3 PR Sequencing (Phase 0 — must merge in this order)

```
PR-001: security/mpesa-hmac-required          (1 file, ~10 lines)
PR-002: fix/payment-request-authorize         (1 file, ~5 lines)
PR-003: fix/password-minimum-8-chars          (1 file, ~1 line)
PR-004: perf/dashboard-cache-10min            (2 files)
PR-005: perf/invoice-service-n1               (1 file)
PR-006: perf/dashboard-service-n1             (1 file)
PR-007: perf/report-service-memory            (1 file)
PR-008: migration/add-tenant-id-all-tables    (18 migration files)
PR-009: feat/tenant-model-and-middleware      (5 files)
PR-010: feat/belongs-to-tenant-trait          (1 trait + 18 model updates)
PR-011: fix/poll-router-traffic-implement     (1 file)
PR-012: feat/subscription-model-rebuild       (2 files)
```

### 3.4 CODEOWNERS

```
# .github/CODEOWNERS
# Platform/tenancy layer — requires architect review
/app/Http/Middleware/ResolveTenant.php          @architect
/app/Models/Traits/BelongsToTenant.php          @architect
/database/migrations/*tenant*                   @architect @dba

# Billing critical path
/app/Services/Billing/                          @billing-lead @architect
/app/Services/Mpesa/                            @billing-lead
/app/Jobs/ProcessMpesaCallbackJob.php           @billing-lead @architect

# Security-sensitive
/app/Http/Middleware/ValidateMpesaCallback.php  @security @architect
/app/Http/Requests/Payment/                     @security
```

### 3.5 Merge Protection Rules (GitHub)

```
Branch: main
- Require 2 approvals
- Require status checks: phpunit, phpstan, security-scan
- Restrict pushes to: release-bot, architect
- No force push

Branch: develop  
- Require 1 approval
- Require status checks: phpunit
- Allow squash merge only
```

### 3.6 .gitattributes Additions

```gitattributes
# .gitattributes
*.php           text eol=lf
*.blade.php     text eol=lf
*.json          text eol=lf
*.md            text eol=lf
*.env.example   text eol=lf

# Migration files — never auto-merge
database/migrations/*.php merge=ours

# Lock files — take ours on conflict
composer.lock   merge=ours
package-lock.json merge=ours
```

---

## SECTION 4 — PHASED IMPLEMENTATION ROADMAP

### Phase 0 — Foundation Fixes (Weeks 1–2)

**Goal:** Fix all CRITICAL and HIGH security/stability bugs. No new features. Zero regressions.  
**Risk:** LOW — all changes are single-file targeted fixes  
**Rollback Safety:** Every fix is independently revertable

#### Phase 0 Files

**Files to Modify:**

```
app/Http/Middleware/ValidateMpesaCallback.php
app/Http/Requests/Payment/StorePaymentRequest.php
app/Http/Controllers/Api/ClientAccountController.php  (password min)
app/Http/Controllers/Api/DashboardController.php      (caching)
app/Services/Dashboard/DashboardService.php           (N+1 fix + cache)
app/Services/Billing/InvoiceService.php               (N+1 fix)
app/Services/Reporting/ReportService.php              (memory fix)
app/Console/Commands/PollRouterTraffic.php             (implement)
routes/api.php                                         (rate limiting)
```

**Files to Create:**

```
app/Http/Traits/ApiResponse.php
app/Jobs/PollRouterTrafficJob.php
database/migrations/2026_05_01_000001_add_missing_indexes.php
database/migrations/2026_05_01_000002_fix_orphaned_migration_references.php
```

#### Phase 0 Implementation

**Fix 1: M-Pesa HMAC (CRITICAL SECURITY)**

```php
// app/Http/Middleware/ValidateMpesaCallback.php — FULL REPLACEMENT
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateMpesaCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.mpesa.callback_secret');

        // Hard abort — never allow unconfigured secret in production
        abort_if(
            empty($secret),
            500,
            'M-Pesa callback secret not configured. Set MPESA_CALLBACK_SECRET in environment.'
        );

        $signature = hash_hmac('sha256', $request->getContent(), $secret);

        abort_if(
            !hash_equals($signature, $request->header('X-Safaricom-Signature', '')),
            401,
            'Invalid M-Pesa callback signature'
        );

        return $next($request);
    }
}
```

**Fix 2: StorePaymentRequest Authorization (CRITICAL SECURITY)**

```php
// app/Http/Requests/Payment/StorePaymentRequest.php
public function authorize(): bool
{
    // Must have permission AND belong to current tenant
    return $this->user()->can('record-payments')
        && $this->user()->tenant_id === optional(app('tenant'))->id;
}
```

> Note: Until tenant middleware is live, use `$this->user()->can('record-payments')` only.
> Add the tenant check in Phase 1 once ResolveTenant middleware is registered.

**Fix 3: Password Minimum**

```php
// app/Http/Controllers/Api/ClientAccountController.php
// Find: 'password' => 'required|min:6'
// Replace:
'password' => 'required|min:8|regex:/^(?=.*[A-Z])(?=.*[0-9]).+$/',
```

**Fix 4: Dashboard Caching**

```php
// app/Services/Dashboard/DashboardService.php
// Wrap getStats() computation:

public function getStats(): array
{
    $tenantId = optional(app('tenant'))->id ?? 'global';

    return Cache::tags(["tenant:{$tenantId}", 'dashboard'])
        ->remember("dashboard:stats:{$tenantId}", 600, function () {
            return $this->computeStats();
        });
}

// Add cache invalidation method:
public function invalidateCache(): void
{
    $tenantId = optional(app('tenant'))->id ?? 'global';
    Cache::tags(["tenant:{$tenantId}", 'dashboard'])->flush();
}
```

> Call `$dashboardService->invalidateCache()` in PaymentService::record() and InvoiceService::markPaid().

**Fix 5: N+1 in InvoiceService::bulkGenerate()**

```php
// app/Services/Billing/InvoiceService.php
// BEFORE (N+1 — loads account per iteration):
$accounts = ClientAccount::where('status', 'active')->where('expiry_date', '<=', $date)->get();
foreach ($accounts as $account) {
    $client = $account->client; // N+1 HERE
}

// AFTER:
$accounts = ClientAccount::with(['client', 'plan'])
    ->where('status', 'active')
    ->where('expiry_date', '<=', $date)
    ->get();
```

**Fix 6: N+1 in DashboardService::getTopDownloaders()**

```php
// app/Services/Dashboard/DashboardService.php
// BEFORE:
$sessions = RadiusSession::orderBy('bytes_in', 'desc')->limit(10)->get();
foreach ($sessions as $s) {
    $name = $s->account->client->name; // N+1 HERE
}

// AFTER:
$sessions = RadiusSession::with(['account.client'])
    ->orderBy('bytes_in', 'desc')
    ->limit(10)
    ->get();
```

**Fix 7: ReportService Memory Issue**

```php
// app/Services/Reporting/ReportService.php
// BEFORE (loads all rows):
public function getIncomeReport($start, $end): array
{
    $payments = Payment::whereBetween('created_at', [$start, $end])->with('client')->get();
    return $payments->groupBy(fn($p) => $p->created_at->format('Y-m'))->toArray();
}

// AFTER (DB aggregation):
public function getIncomeReport($start, $end): array
{
    return Payment::selectRaw(
            'DATE_FORMAT(created_at, "%Y-%m") as month,
             SUM(amount) as total,
             COUNT(*) as count,
             payment_method,
             SUM(CASE WHEN status = "completed" THEN amount ELSE 0 END) as completed_total'
        )
        ->whereBetween('created_at', [$start, $end])
        ->where('status', 'completed')
        ->groupBy('month', 'payment_method')
        ->orderBy('month')
        ->get()
        ->toArray();
}
```

**Fix 8: Rate Limiting on Export Endpoints**

```php
// routes/api.php — add to export route group
Route::middleware(['throttle:exports'])->group(function () {
    Route::get('/reports/{type}/export', [ReportController::class, 'export']);
    Route::get('/clients/export', [ClientController::class, 'export']);
    Route::get('/payments/export', [PaymentController::class, 'export']);
});

// app/Providers/RouteServiceProvider.php — add limiter
RateLimiter::for('exports', fn($req) =>
    Limit::perHour(20)->by(optional($req->user())->id ?? $req->ip())
);
```

**Fix 9: ApiResponse Trait**

```php
// app/Http/Traits/ApiResponse.php
<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = null, int $status = 200): JsonResponse
    {
        $response = ['success' => true, 'data' => $data, 'message' => $message];

        if ($data instanceof LengthAwarePaginator) {
            $response['data'] = $data->items();
            $response['meta'] = [
                'page'      => $data->currentPage(),
                'per_page'  => $data->perPage(),
                'total'     => $data->total(),
                'last_page' => $data->lastPage(),
            ];
        }

        return response()->json($response, $status);
    }

    protected function error(string $message, array $errors = [], int $status = 422, string $code = 'ERROR'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'code'    => $code,
        ], $status);
    }

    protected function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
```

**Add to every controller:**
```php
use App\Http\Traits\ApiResponse;

class ClientController extends Controller
{
    use ApiResponse;
    // ...
}
```

**Fix 10: Missing Indexes Migration**

```php
// database/migrations/2026_05_01_000001_add_missing_indexes.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'due_date'],     'idx_invoices_status_due');
            $table->index(['client_id', 'status'],    'idx_invoices_client_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['client_id', 'created_at'], 'idx_payments_client_date');
            $table->index(['invoice_id', 'status'],    'idx_payments_invoice_status');
        });

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->index(['status', 'expiry_date'],  'idx_accounts_status_expiry');
            $table->index(['client_id', 'status'],    'idx_accounts_client_status');
        });

        // Add created_at index to system_logs (currently missing per audit)
        Schema::table('system_logs', function (Blueprint $table) {
            $table->index(['created_at'], 'idx_system_logs_created');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_status_due');
            $table->dropIndex('idx_invoices_client_status');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_client_date');
            $table->dropIndex('idx_payments_invoice_status');
        });
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_accounts_status_expiry');
            $table->dropIndex('idx_accounts_client_status');
        });
        Schema::table('system_logs', function (Blueprint $table) {
            $table->dropIndex('idx_system_logs_created');
        });
    }
};
```

**Phase 0 Test Commands:**
```bash
php artisan test --filter=MpesaCallbackTest
php artisan test --filter=PaymentAuthorizationTest
php artisan test --filter=DashboardCacheTest
php artisan test --filter=InvoiceServiceTest
php artisan migrate:fresh --seed --env=testing
```

**Phase 0 Rollback:**
```bash
# Each fix is a single-file change. Rollback per PR:
git revert <commit-sha>  # safe for every Phase 0 commit
php artisan migrate:rollback --step=2  # for index migrations
```

---

### Phase 1 — Multi-Tenancy Foundation (Weeks 3–8)

**Goal:** Add complete tenant isolation to the existing system. Every existing query must be tenant-scoped.  
**Risk:** HIGH — touches every model and every migration  
**Rollback Safety:** MEDIUM — tenant_id is nullable initially; old behavior preserved until global scopes enabled

**Blocking Dependencies:** All Phase 0 PRs merged.

#### Phase 1 Files to Create

```
app/Models/Tenant.php
app/Models/TenantLimit.php
app/Models/TenantDomain.php
app/Models/Traits/BelongsToTenant.php
app/Http/Middleware/ResolveTenant.php
app/Services/Tenancy/TenantService.php
app/Services/Tenancy/TenantOnboardingService.php
app/Http/Controllers/Api/TenantController.php         (platform admin)
app/Http/Controllers/Auth/TenantRegistrationController.php
app/Providers/TenancyServiceProvider.php
database/migrations/2026_05_10_000001_create_tenants_table.php
database/migrations/2026_05_10_000002_create_tenant_limits_table.php
database/migrations/2026_05_10_000003_create_tenant_domains_table.php
database/migrations/2026_05_10_000004_add_tenant_id_to_users.php
database/migrations/2026_05_10_000005_add_tenant_id_to_clients.php
database/migrations/2026_05_10_000006_add_tenant_id_to_client_accounts.php
database/migrations/2026_05_10_000007_add_tenant_id_to_plans.php
database/migrations/2026_05_10_000008_add_tenant_id_to_routers.php
database/migrations/2026_05_10_000009_add_tenant_id_to_invoices.php
database/migrations/2026_05_10_000010_add_tenant_id_to_payments.php
database/migrations/2026_05_10_000011_add_tenant_id_to_tickets.php
database/migrations/2026_05_10_000012_add_tenant_id_to_ticket_replies.php
database/migrations/2026_05_10_000013_add_tenant_id_to_sms_logs.php
database/migrations/2026_05_10_000014_add_tenant_id_to_expenditures.php
database/migrations/2026_05_10_000015_add_tenant_id_to_inventory_items.php
database/migrations/2026_05_10_000016_add_tenant_id_to_network_traffic.php
database/migrations/2026_05_10_000017_add_tenant_id_to_radius_sessions.php
database/migrations/2026_05_10_000018_add_tenant_id_to_system_logs.php
database/migrations/2026_05_10_000019_add_tenant_id_to_settings.php
database/migrations/2026_05_10_000020_backfill_tenant_id_existing_data.php
database/migrations/2026_05_10_000021_make_tenant_id_not_null_all_tables.php
database/migrations/2026_05_10_000022_add_tenant_composite_indexes.php
scripts/backfill-tenant-ids.sh
```

#### Phase 1 Complete Implementation

**Tenant Model:**

```php
// app/Models/Tenant.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = [
        'uuid', 'name', 'slug', 'custom_domain', 'plan',
        'status', 'trial_ends_at', 'settings', 'branding',
        'timezone', 'currency',
    ];

    protected $casts = [
        'settings'      => 'array',
        'branding'      => 'array',
        'trial_ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->uuid ??= Str::uuid();
        });
    }

    public static function resolveFromRequest(Request $request): ?static
    {
        // 1. Subdomain resolution
        $host = $request->getHost();
        $appDomain = config('app.domain', 'primebill.app');

        if (str_ends_with($host, ".{$appDomain}")) {
            $slug = str_replace(".{$appDomain}", '', $host);
            return static::where('slug', $slug)->where('status', '!=', 'cancelled')->first();
        }

        // 2. Custom domain resolution
        $domain = TenantDomain::where('domain', $host)->first();
        if ($domain) {
            return $domain->tenant;
        }

        // 3. API key header (machine-to-machine)
        $tenantId = $request->header('X-Tenant-ID');
        if ($tenantId) {
            return static::find($tenantId);
        }

        return null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function limits(): HasOne
    {
        return $this->hasOne(TenantLimit::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trial']);
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trial' && $this->trial_ends_at?->isFuture();
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
```

**BelongsToTenant Trait:**

```php
// app/Models/Traits/BelongsToTenant.php
<?php

namespace App\Models\Traits;

use App\Models\Scopes\TenantScope;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        // Auto-apply tenant scope on all queries
        static::addGlobalScope(new TenantScope());

        // Auto-assign tenant_id on create
        static::creating(function ($model) {
            if (app()->has('tenant') && is_null($model->tenant_id)) {
                $model->tenant_id = app('tenant')->id;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
```

**Tenant Scope:**

```php
// app/Models/Scopes/TenantScope.php
<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->has('tenant')) {
            return; // Console commands, platform admin — no scope applied
        }

        $builder->where($model->getTable() . '.tenant_id', app('tenant')->id);
    }
}
```

**ResolveTenant Middleware:**

```php
// app/Http/Middleware/ResolveTenant.php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::resolveFromRequest($request);

        if (!$tenant) {
            // Platform admin routes don't need a tenant
            if ($request->is('api/platform/*')) {
                return $next($request);
            }
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
                'code'    => 'TENANT_NOT_FOUND',
            ], 404);
        }

        if (!$tenant->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant account is ' . $tenant->status,
                'code'    => 'TENANT_INACTIVE',
            ], 403);
        }

        // Bind tenant to container — all services + global scopes use this
        app()->instance('tenant', $tenant);

        // Share with views / Blade
        view()->share('currentTenant', $tenant);

        return $next($request);
    }
}
```

**Register middleware in bootstrap/app.php (Laravel 11):**

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'tenant'          => \App\Http\Middleware\ResolveTenant::class,
        'validate.mpesa'  => \App\Http\Middleware\ValidateMpesaCallback::class,
    ]);

    // Apply to all API routes
    $middleware->appendToGroup('api', [
        \App\Http\Middleware\ResolveTenant::class,
    ]);
})
```

**Tenant ID Migration Template (repeat for all 18 tables):**

```php
// database/migrations/2026_05_10_000004_add_tenant_id_to_users.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ONLINE SAFE: Adding nullable column never locks table in MySQL 8 InnoDB
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
```

**Backfill Migration (CRITICAL — run after all tenant_id columns added):**

```php
// database/migrations/2026_05_10_000020_backfill_tenant_id_existing_data.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // This migration creates the first "default" tenant and assigns all
    // existing data to it. This is a one-time operation for existing deployments.
    
    public function up(): void
    {
        // Create the default tenant from existing settings
        $tenantId = DB::table('tenants')->insertGetId([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'name'       => DB::table('settings')->where('key', 'company_name')->value('value') ?? 'Default ISP',
            'slug'       => 'default',
            'plan'       => 'growth',
            'status'     => 'active',
            'settings'   => '{}',
            'branding'   => '{}',
            'timezone'   => 'Africa/Nairobi',
            'currency'   => 'KES',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assign all existing records to default tenant
        $tables = [
            'users', 'clients', 'client_accounts', 'plans', 'routers',
            'invoices', 'payments', 'tickets', 'ticket_replies', 'sms_logs',
            'expenditures', 'inventory_items', 'network_traffic',
            'radius_sessions', 'system_logs', 'settings',
        ];

        foreach ($tables as $table) {
            DB::table($table)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }

        // Create default limits
        DB::table('tenant_limits')->insert([
            'tenant_id'          => $tenantId,
            'max_clients'        => 99999,  // unlimited for migrated tenant
            'max_routers'        => 99999,
            'max_sms_per_month'  => 99999,
            'max_users'          => 99999,
            'can_white_label'    => 1,
            'can_use_api'        => 1,
        ]);
    }

    public function down(): void
    {
        // Remove backfill — set all back to null
        $tables = [
            'users', 'clients', 'client_accounts', 'plans', 'routers',
            'invoices', 'payments', 'tickets', 'ticket_replies', 'sms_logs',
            'expenditures', 'inventory_items', 'network_traffic',
            'radius_sessions', 'system_logs', 'settings',
        ];

        foreach ($tables as $table) {
            DB::table($table)->update(['tenant_id' => null]);
        }

        DB::table('tenant_limits')->truncate();
        DB::table('tenants')->where('slug', 'default')->delete();
    }
};
```

**Make NOT NULL (run ONLY after verifying backfill is complete):**

```php
// database/migrations/2026_05_10_000021_make_tenant_id_not_null_all_tables.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // PREREQUISITE: Verify no NULLs exist before running
    // SELECT table_name, COUNT(*) FROM information_schema.columns 
    // WHERE column_name = 'tenant_id' -- check each table manually first
    
    public function up(): void
    {
        $tables = [
            'users', 'clients', 'client_accounts', 'plans', 'routers',
            'invoices', 'payments', 'tickets', 'ticket_replies', 'sms_logs',
            'expenditures', 'inventory_items', 'network_traffic',
            'radius_sessions', 'system_logs',
        ];

        foreach ($tables as $table) {
            // Safety check before making NOT NULL
            $nullCount = DB::table($table)->whereNull('tenant_id')->count();
            if ($nullCount > 0) {
                throw new \RuntimeException(
                    "Cannot make tenant_id NOT NULL on {$table}: {$nullCount} rows still have NULL tenant_id. Run backfill first."
                );
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'users', 'clients', 'client_accounts', 'plans', 'routers',
            'invoices', 'payments', 'tickets', 'ticket_replies', 'sms_logs',
            'expenditures', 'inventory_items', 'network_traffic',
            'radius_sessions', 'system_logs',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        }
    }
};
```

**Composite Indexes Migration:**

```php
// database/migrations/2026_05_10_000022_add_tenant_composite_indexes.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ONLINE SAFE: InnoDB online DDL for index additions (MySQL 8)
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'due_date'],    'idx_invoices_tenant_status_due');
            $table->index(['tenant_id', 'client_id', 'status'],   'idx_invoices_tenant_client');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'client_id', 'created_at'], 'idx_payments_tenant_client_date');
            $table->index(['tenant_id', 'invoice_id', 'status'],    'idx_payments_tenant_invoice');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'created_at'],  'idx_clients_tenant_status');
            $table->index(['tenant_id', 'phone'],                  'idx_clients_tenant_phone');
            $table->index(['tenant_id', 'email'],                  'idx_clients_tenant_email');
        });

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'expiry_date'], 'idx_accounts_tenant_expiry');
            $table->index(['tenant_id', 'client_id', 'status'],   'idx_accounts_tenant_client');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'client_id', 'created_at'], 'idx_sms_tenant_client');
            $table->index(['tenant_id', 'status', 'created_at'],    'idx_sms_tenant_status');
        });

        Schema::table('system_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'idx_logs_tenant_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_tenant_status_due');
            $table->dropIndex('idx_invoices_tenant_client');
        });
        // ... (repeat for each table)
    }
};
```

**Apply BelongsToTenant to all 18 models:**

```php
// Example: app/Models/Client.php — add to every tenant-scoped model
use App\Models\Traits\BelongsToTenant;

class Client extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant; // ← add trait
    // ... rest unchanged
}
```

**Phase 1 Test Commands:**

```bash
# Tenant isolation test (add to test suite)
php artisan test --filter=TenantIsolationTest
php artisan test --filter=TenantResolutionTest
php artisan test --filter=GlobalScopeTest

# Verify backfill
php artisan tinker --execute="DB::table('clients')->whereNull('tenant_id')->count()"
# Expected: 0

# Verify isolation
php artisan tinker --execute="
    \$t = App\Models\Tenant::first();
    app()->instance('tenant', \$t);
    echo App\Models\Client::count();
"
```

---

### Phase 2 — Core Billing & Subscription (Weeks 9–14)

**Goal:** Rebuild broken SubscriptionService, add Dunning, Wallet, Tax, and fix billing engine gaps.  
**Risk:** HIGH  
**Blocking Dependencies:** Phase 1 complete, all models tenant-scoped.

#### Phase 2 New Tables (create in this order)

```
subscriptions
subscription_changes
dunning_policies
dunning_notices
client_wallets
wallet_transactions
invoice_line_items
credit_notes
tax_rates
invoice_taxes
payment_gateways
payment_refunds
invoice_templates
addon_services
client_addons
```

**Subscription Model (complete rebuild):**

```php
// app/Models/Subscription.php
<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'client_id', 'client_account_id', 'plan_id',
        'status', 'billing_cycle', 'cycle_day',
        'started_at', 'current_period_start', 'current_period_end',
        'next_billing_at', 'cancelled_at', 'cancel_reason',
        'auto_renew', 'trial_ends_at',
    ];

    protected $casts = [
        'started_at'           => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'next_billing_at'      => 'datetime',
        'cancelled_at'         => 'datetime',
        'trial_ends_at'        => 'datetime',
        'auto_renew'           => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDueForRenewal(): bool
    {
        return $this->isActive()
            && $this->auto_renew
            && $this->next_billing_at->isPast();
    }
}
```

**Subscription Migration:**

```php
// database/migrations/2026_05_20_000001_create_subscriptions_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('client_account_id');
            $table->unsignedBigInteger('plan_id');
            $table->enum('status', ['active', 'pending', 'suspended', 'cancelled', 'expired'])
                  ->default('active');
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'annual', 'custom'])
                  ->default('monthly');
            $table->tinyInteger('cycle_day')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('plan_id')->references('id')->on('plans');

            $table->index(['tenant_id', 'status', 'next_billing_at'], 'idx_subs_tenant_renewal');
            $table->index(['tenant_id', 'client_id', 'status'], 'idx_subs_tenant_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

**Dunning Policy Migration:**

```php
// database/migrations/2026_05_20_000003_create_dunning_tables.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->integer('grace_period_days')->default(3);
            $table->json('steps');  // array of step objects
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'is_default']);
        });

        Schema::create('dunning_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('policy_id');
            $table->integer('step_index');
            $table->string('action');  // notify, restrict_speed, suspend, terminate
            $table->json('channel');   // ["sms","email"]
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'status', 'scheduled_at'], 'idx_dunning_tenant_scheduled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_notices');
        Schema::dropIfExists('dunning_policies');
    }
};
```

**Wallet Migration:**

```php
// database/migrations/2026_05_20_000005_create_wallet_tables.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('client_id');
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->char('currency', 3)->default('KES');
            $table->timestamp('last_activity')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->unique(['tenant_id', 'client_id']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('wallet_id');
            $table->unsignedBigInteger('client_id');
            $table->enum('type', ['credit', 'debit', 'refund', 'adjustment']);
            $table->decimal('amount', 12, 2);
            $table->decimal('running_balance', 12, 2);
            $table->string('reference_type', 50)->nullable();  // payment, credit_note, manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'wallet_id', 'created_at'], 'idx_wallet_timeline');
            $table->index(['tenant_id', 'client_id', 'created_at'], 'idx_wallet_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('client_wallets');
    }
};
```

---

### Phase 3 — Network & Provisioning (Weeks 15–18)

**Goal:** IPAM, FUP engine, network abstraction layer, RADIUS accounting webhook, hotspot/vouchers.  
**Risk:** MEDIUM — new modules, existing provisioning preserved  
**Blocking Dependencies:** Phase 2 complete, subscriptions working.

**New Tables:**

```
ip_pools
ip_allocations
ip_allocation_history
plan_fup_tiers
client_usage_cycles
radius_servers
hotspot_zones
voucher_batches
vouchers
```

**NetworkProvisionerInterface:**

```php
// app/Contracts/NetworkProvisionerInterface.php
<?php

namespace App\Contracts;

use App\Models\ClientAccount;

interface NetworkProvisionerInterface
{
    public function createUser(ClientAccount $account): bool;
    public function disableUser(ClientAccount $account): bool;
    public function enableUser(ClientAccount $account): bool;
    public function updateBandwidth(ClientAccount $account, int $downloadKbps, int $uploadKbps): bool;
    public function sendCoA(ClientAccount $account, array $avps): bool;
    public function getUserSession(ClientAccount $account): ?array;
    public function getInterfaceTraffic(): array;
}
```

---

### Phase 4 — Ecosystem & Growth (Weeks 19–24)

**Goal:** Agents, webhooks, SMS campaigns, advanced reporting, KYC, platform billing, audit trail enhancement.  
**Risk:** LOW — all additive new modules  
**Blocking Dependencies:** Phase 3 complete.

---

## SECTION 5 — COMPLETE TARGET PROJECT STRUCTURE

```
primebill-api/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── GenerateMonthlyInvoices.php         (existing — enhance)
│   │       ├── SuspendOverdueAccounts.php           (existing — replace with dunning)
│   │       ├── SendInvoiceReminders.php             (existing — keep)
│   │       ├── PollRouterTraffic.php                (existing — implement)
│   │       ├── SyncRadiusUsers.php                  (existing — enhance)
│   │       ├── CleanOldLogs.php                     (existing — keep)
│   │       ├── RunDunningEngine.php                 ← NEW Phase 2
│   │       ├── SyncFupUsage.php                     ← NEW Phase 3
│   │       ├── ReactivatePaidAccounts.php           ← NEW Phase 1
│   │       ├── ReconcileMpesaPayments.php           ← NEW Phase 1
│   │       ├── ExpireVouchers.php                   ← NEW Phase 3
│   │       ├── ResetFupCycles.php                   ← NEW Phase 3
│   │       └── GenerateSlaReports.php               ← NEW Phase 4
│   │
│   ├── Contracts/                                    ← NEW
│   │   ├── NetworkProvisionerInterface.php
│   │   ├── SmsGatewayInterface.php                  (move from Services/)
│   │   ├── PaymentGatewayInterface.php              ← NEW
│   │   └── NotificationChannelInterface.php         ← NEW
│   │
│   ├── DTOs/                                         ← NEW
│   │   ├── CreateSubscriptionDTO.php
│   │   ├── RecordPaymentDTO.php
│   │   ├── GenerateInvoiceDTO.php
│   │   └── ProvisionAccountDTO.php
│   │
│   ├── Events/                                       ← NEW
│   │   ├── PaymentReceived.php
│   │   ├── InvoiceGenerated.php
│   │   ├── AccountSuspended.php
│   │   ├── AccountActivated.php
│   │   ├── SubscriptionRenewed.php
│   │   └── FupThresholdReached.php
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/                                 (existing — add ApiResponse trait)
│   │   │   │   └── ...
│   │   │   ├── Platform/                            ← NEW
│   │   │   │   ├── TenantController.php
│   │   │   │   ├── PlatformPlanController.php
│   │   │   │   └── ImpersonationController.php
│   │   │   └── Portal/                              (existing)
│   │   │
│   │   ├── Middleware/
│   │   │   ├── ResolveTenant.php                    ← NEW Phase 1
│   │   │   ├── ValidateMpesaCallback.php            (existing — fix)
│   │   │   ├── EnsureTenantActive.php               ← NEW Phase 1
│   │   │   └── RequireFeatureFlag.php               ← NEW Phase 2
│   │   │
│   │   ├── Requests/                                (existing — fix authorize())
│   │   │   └── ...
│   │   │
│   │   ├── Resources/                               ← NEW (JSON API Resources)
│   │   │   ├── ClientResource.php
│   │   │   ├── InvoiceResource.php
│   │   │   ├── PaymentResource.php
│   │   │   ├── SubscriptionResource.php
│   │   │   └── TenantResource.php
│   │   │
│   │   └── Traits/
│   │       └── ApiResponse.php                      ← NEW Phase 0
│   │
│   ├── Jobs/
│   │   ├── SendSmsJob.php                           (existing — add timeout)
│   │   ├── SendBulkSmsJob.php                       (existing)
│   │   ├── ProcessMpesaPayment.php                  (existing — rename to ProcessMpesaCallbackJob)
│   │   ├── ProcessMpesaCallbackJob.php              ← RENAME + enhance
│   │   ├── ReconcilePaymentJob.php                  ← NEW Phase 1
│   │   ├── ProvisionRadiusUserJob.php               ← NEW Phase 1
│   │   ├── DeprovisionRadiusUserJob.php             ← NEW Phase 1
│   │   ├── UpdateMikrotikBandwidthJob.php           ← NEW Phase 3
│   │   ├── SendCoaPacketJob.php                     ← NEW Phase 3
│   │   ├── GenerateInvoiceJob.php                   ← NEW Phase 2
│   │   ├── ProcessDunningStepJob.php                ← NEW Phase 2
│   │   ├── RenewSubscriptionJob.php                 ← NEW Phase 2
│   │   ├── SendEmailJob.php                         ← NEW Phase 1
│   │   ├── DeliverWebhookJob.php                    ← NEW Phase 4
│   │   ├── GenerateReportJob.php                    ← NEW Phase 2
│   │   ├── SyncFupUsageJob.php                      ← NEW Phase 3
│   │   ├── PollRouterTrafficJob.php                 ← NEW Phase 0
│   │   └── GenerateInvoicePdfJob.php               ← NEW Phase 2
│   │
│   ├── Listeners/                                   ← NEW
│   │   ├── SendPaymentNotification.php
│   │   ├── ApplyWalletCredit.php
│   │   ├── TriggerWebhookDelivery.php
│   │   └── UpdateSubscriptionStatus.php
│   │
│   ├── Models/
│   │   ├── (all existing models — add BelongsToTenant trait)
│   │   ├── Tenant.php                               ← NEW Phase 1
│   │   ├── TenantLimit.php                          ← NEW Phase 1
│   │   ├── TenantDomain.php                         ← NEW Phase 1
│   │   ├── Subscription.php                         ← NEW Phase 1 (rebuild)
│   │   ├── SubscriptionChange.php                   ← NEW Phase 2
│   │   ├── DunningPolicy.php                        ← NEW Phase 2
│   │   ├── DunningNotice.php                        ← NEW Phase 2
│   │   ├── ClientWallet.php                         ← NEW Phase 2
│   │   ├── WalletTransaction.php                    ← NEW Phase 2
│   │   ├── InvoiceLineItem.php                      ← NEW Phase 2
│   │   ├── CreditNote.php                           ← NEW Phase 2
│   │   ├── TaxRate.php                              ← NEW Phase 2
│   │   ├── InvoiceTax.php                           ← NEW Phase 2
│   │   ├── PaymentGateway.php                       ← NEW Phase 2
│   │   ├── IpPool.php                               ← NEW Phase 3
│   │   ├── IpAllocation.php                         ← NEW Phase 3
│   │   ├── PlanFupTier.php                          ← NEW Phase 3
│   │   ├── ClientUsageCycle.php                     ← NEW Phase 3
│   │   ├── VoucherBatch.php                         ← NEW Phase 3
│   │   ├── Voucher.php                              ← NEW Phase 3
│   │   ├── Agent.php                                ← NEW Phase 4
│   │   ├── AgentCommission.php                      ← NEW Phase 4
│   │   ├── WebhookEndpoint.php                      ← NEW Phase 4
│   │   ├── WebhookDelivery.php                      ← NEW Phase 4
│   │   ├── NotificationTemplate.php                 ← NEW Phase 2
│   │   ├── AuditLog.php                             ← NEW Phase 2 (replaces SystemLog)
│   │   └── Scopes/
│   │       └── TenantScope.php                      ← NEW Phase 1
│   │
│   ├── Policies/                                    ← NEW (Phase 1)
│   │   ├── ClientPolicy.php
│   │   ├── InvoicePolicy.php
│   │   └── PaymentPolicy.php
│   │
│   ├── Providers/
│   │   ├── AppServiceProvider.php                   (existing)
│   │   ├── AuthServiceProvider.php                  (existing)
│   │   ├── TenancyServiceProvider.php               ← NEW Phase 1
│   │   └── EventServiceProvider.php                 ← NEW Phase 2
│   │
│   └── Services/
│       ├── Auth/AuthService.php                     (existing)
│       ├── Billing/
│       │   ├── InvoiceService.php                   (existing — fix N+1, add line items)
│       │   ├── PaymentService.php                   (existing — add wallet, fix race)
│       │   ├── SubscriptionService.php              (rebuild from scratch)
│       │   ├── DunningService.php                   ← NEW Phase 2
│       │   ├── WalletService.php                    ← NEW Phase 2
│       │   ├── TaxService.php                       ← NEW Phase 2
│       │   └── ProrationService.php                 ← NEW Phase 2
│       ├── Client/ClientService.php                 (existing — add tenant guard)
│       ├── Dashboard/DashboardService.php           (existing — add caching)
│       ├── Finance/
│       │   ├── ExpenditureService.php               (existing)
│       │   └── CommissionService.php                (existing — needs model)
│       ├── Inventory/InventoryService.php           (existing)
│       ├── Mpesa/MpesaService.php                   (existing — fix race condition)
│       ├── Network/
│       │   ├── MikroTikService.php                  (existing)
│       │   ├── RouterService.php                    (existing)
│       │   ├── MikroTikProvisioner.php              ← NEW Phase 3 (implements interface)
│       │   ├── RadiusProvisioner.php                ← NEW Phase 3
│       │   └── NetworkProvisionerFactory.php        ← NEW Phase 3
│       ├── Reporting/ReportService.php              (existing — fix memory, add async)
│       ├── Settings/SettingsService.php             (existing — make tenant-aware)
│       ├── Sms/                                     (existing)
│       ├── Tenancy/
│       │   ├── TenantService.php                    ← NEW Phase 1
│       │   └── TenantOnboardingService.php          ← NEW Phase 1
│       ├── Fup/FupService.php                       ← NEW Phase 3
│       ├── Ipam/IpamService.php                     ← NEW Phase 3
│       ├── Hotspot/HotspotService.php               ← NEW Phase 3
│       ├── Webhook/WebhookService.php               ← NEW Phase 4
│       ├── Agent/AgentService.php                   ← NEW Phase 4
│       └── Notification/NotificationService.php    ← NEW Phase 2
│
├── database/
│   ├── migrations/                                  (18 existing + 50+ new)
│   ├── factories/
│   │   ├── TenantFactory.php                        ← NEW
│   │   ├── SubscriptionFactory.php                  ← NEW
│   │   └── ...
│   └── seeders/
│       ├── TenantSeeder.php                         ← NEW
│       ├── DunningPolicySeeder.php                  ← NEW
│       └── ...
│
├── routes/
│   ├── api.php                                      (existing — restructure to v1)
│   ├── platform.php                                 ← NEW
│   └── console.php                                  (existing — add new commands)
│
├── config/
│   ├── mpesa.php                                    (existing)
│   ├── sms.php                                      (existing)
│   ├── tenancy.php                                  ← NEW
│   └── horizon.php                                  ← NEW
│
├── scripts/
│   ├── backfill-tenant-ids.sh                       ← NEW
│   ├── migrate-phase-1.sh                           ← NEW
│   ├── cache-warm.sh                                ← NEW
│   └── deploy.sh                                    ← NEW
│
├── docker/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── nginx/primebill.conf
│   └── supervisor/horizon.conf
│
├── .github/
│   ├── workflows/
│   │   ├── tests.yml
│   │   ├── deploy-staging.yml
│   │   └── deploy-production.yml
│   ├── CODEOWNERS
│   └── pull_request_template.md
│
└── tests/
    ├── Feature/
    │   ├── Tenancy/
    │   │   ├── TenantIsolationTest.php              ← NEW (critical)
    │   │   ├── TenantResolutionTest.php             ← NEW
    │   │   └── GlobalScopeTest.php                  ← NEW
    │   ├── Billing/
    │   │   ├── SubscriptionTest.php                 ← NEW
    │   │   ├── DunningTest.php                      ← NEW
    │   │   └── InvoiceLineItemTest.php              ← NEW
    │   ├── Payments/
    │   │   ├── MpesaCallbackTest.php                ← NEW
    │   │   └── WalletTest.php                       ← NEW
    │   └── Network/
    │       └── FupEngineTe st.php                   ← NEW
    └── Unit/
        ├── Services/
        └── Models/
```

---

## SECTION 6 — DATABASE MIGRATION MASTER PLAN

### 6.1 Migration Execution Sequence

```
PHASE 0 MIGRATIONS (run immediately — online safe):
2026_05_01_000001_add_missing_indexes.php              [additive, online safe]
2026_05_01_000002_fix_orphaned_migration_references.php [additive, online safe]

PHASE 1 MIGRATIONS (run in strict order):
2026_05_10_000001_create_tenants_table.php             [new table, safe]
2026_05_10_000002_create_tenant_limits_table.php       [new table, safe]
2026_05_10_000003_create_tenant_domains_table.php      [new table, safe]
2026_05_10_000004 → 000019: add_tenant_id_to_*.php     [nullable column, safe per table]
2026_05_10_000020_backfill_tenant_id_existing_data.php [data migration — VERIFY before next]
2026_05_10_000021_make_tenant_id_not_null_all_tables.php [constraint — run LAST after verify]
2026_05_10_000022_add_tenant_composite_indexes.php     [indexes — online safe]

PHASE 2 MIGRATIONS:
2026_05_20_000001_create_subscriptions_table.php
2026_05_20_000002_create_subscription_changes_table.php
2026_05_20_000003_create_dunning_tables.php
2026_05_20_000004_create_invoice_line_items_table.php
2026_05_20_000005_create_wallet_tables.php
2026_05_20_000006_create_tax_tables.php
2026_05_20_000007_create_payment_gateway_table.php
2026_05_20_000008_create_credit_notes_table.php
2026_05_20_000009_create_audit_logs_table.php
2026_05_20_000010_create_notification_templates_table.php

PHASE 3 MIGRATIONS:
2026_06_01_000001_create_ip_pools_table.php
2026_06_01_000002_create_ip_allocations_table.php
2026_06_01_000003_create_plan_fup_tiers_table.php
2026_06_01_000004_create_client_usage_cycles_table.php
2026_06_01_000005_create_radius_servers_table.php
2026_06_01_000006_create_hotspot_zones_table.php
2026_06_01_000007_create_voucher_tables.php

PHASE 4 MIGRATIONS:
2026_07_01_000001_create_agents_table.php
2026_07_01_000002_create_agent_commissions_table.php
2026_07_01_000003_create_webhook_tables.php
2026_07_01_000004_create_sms_campaigns_table.php
2026_07_01_000005_create_platform_billing_tables.php
2026_07_01_000006_create_sla_tables.php
```

### 6.2 Online-Safety Classification

| Migration | Online Safe | Requires Maintenance | Notes |
|---|---|---|---|
| Add nullable column | ✅ Yes | No | InnoDB instant ADD COLUMN |
| Add index | ✅ Yes | No | InnoDB online DDL |
| Create new table | ✅ Yes | No | No existing table touched |
| Backfill tenant_id | ✅ Yes (batched) | No | Run in chunks of 1000 |
| Make column NOT NULL | ⚠️ Careful | Depends | Safe only after verified backfill |
| Drop column | ❌ No | Yes | Phase 4 only, maintenance window |
| Change column type | ❌ No | Yes | Avoid if possible |

### 6.3 Backfill Verification Queries

Run these before executing migration 000021:

```sql
-- Must return 0 for all tables before NOT NULL migration
SELECT 'users' as t, COUNT(*) FROM users WHERE tenant_id IS NULL
UNION ALL SELECT 'clients', COUNT(*) FROM clients WHERE tenant_id IS NULL
UNION ALL SELECT 'invoices', COUNT(*) FROM invoices WHERE tenant_id IS NULL
UNION ALL SELECT 'payments', COUNT(*) FROM payments WHERE tenant_id IS NULL
UNION ALL SELECT 'client_accounts', COUNT(*) FROM client_accounts WHERE tenant_id IS NULL;

-- Expected: All zeros
```
## SECTION 7 — TENANCY IMPLEMENTATION PLAN

### 7.1 Complete Middleware Stack Order

```php
// bootstrap/app.php — EXACT middleware registration order
->withMiddleware(function (Middleware $middleware) {

    // Global middleware (all requests)
    $middleware->append([
        \Illuminate\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
    ]);

    // API middleware group
    $middleware->appendToGroup('api', [
        \Illuminate\Routing\Middleware\ThrottleRequests::class . ':api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \App\Http\Middleware\ResolveTenant::class,     // ← FIRST: set tenant context
        \App\Http\Middleware\EnsureTenantActive::class, // ← SECOND: check tenant status
    ]);

    $middleware->alias([
        'auth'             => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'tenant'           => \App\Http\Middleware\ResolveTenant::class,
        'tenant.active'    => \App\Http\Middleware\EnsureTenantActive::class,
        'validate.mpesa'   => \App\Http\Middleware\ValidateMpesaCallback::class,
        'feature'          => \App\Http\Middleware\RequireFeatureFlag::class,
        'throttle'         => \Illuminate\Routing\Middleware\ThrottleRequests::class,
    ]);
})
```

### 7.2 TenancyServiceProvider

```php
// app/Providers/TenancyServiceProvider.php
<?php

namespace App\Providers;

use App\Models\Tenant;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind tenant as singleton — resolved once per request
        $this->app->singleton('tenant', function () {
            return null; // Replaced by ResolveTenant middleware
        });
    }

    public function boot(): void
    {
        // Make tenant available as a facade/helper
        if (!function_exists('current_tenant')) {
            function current_tenant(): ?Tenant {
                return app('tenant');
            }
        }

        // Tenant-aware cache prefix
        $this->app->bind('cache.store', function ($app) {
            $tenant = app('tenant');
            if ($tenant) {
                config(['cache.prefix' => "t{$tenant->id}"]);
            }
            return $app['cache']->driver();
        });
    }
}
```

Register in `bootstrap/providers.php`:
```php
App\Providers\TenancyServiceProvider::class,
```

### 7.3 Tenant-Aware Queue Jobs

All jobs that process tenant data MUST carry tenant context:

```php
// app/Jobs/Concerns/HasTenantContext.php
<?php

namespace App\Jobs\Concerns;

use App\Models\Tenant;

trait HasTenantContext
{
    public int $tenantId;

    protected function setTenantContext(): void
    {
        if (isset($this->tenantId)) {
            $tenant = Tenant::find($this->tenantId);
            if ($tenant) {
                app()->instance('tenant', $tenant);
            }
        }
    }
}
```

Usage in any job:
```php
class GenerateInvoiceJob implements ShouldQueue
{
    use HasTenantContext;

    public function __construct(int $tenantId, int $clientId)
    {
        $this->tenantId = $tenantId;
        $this->clientId = $clientId;
    }

    public function handle(): void
    {
        $this->setTenantContext(); // ← Restores tenant before any DB query
        // ... rest of job logic — global scopes now apply correctly
    }
}
```

### 7.4 Tenant-Aware Cache

```php
// Helper to build tenant-namespaced cache tags
// Use everywhere instead of raw Cache::remember():

// WRONG:
Cache::remember('dashboard_stats', 600, fn() => ...);

// CORRECT:
$tenant = app('tenant');
Cache::tags(["tenant:{$tenant->id}", 'dashboard'])
    ->remember("dashboard:stats:{$tenant->id}", 600, fn() => ...);
```

### 7.5 Tenant Onboarding Service

```php
// app/Services/Tenancy/TenantOnboardingService.php
<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantLimit;
use App\Models\User;
use App\Models\DunningPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantOnboardingService
{
    public function createTenant(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            // 1. Create tenant
            $tenant = Tenant::create([
                'name'          => $data['company_name'],
                'slug'          => $data['slug'],
                'plan'          => $data['plan'] ?? 'starter',
                'status'        => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'timezone'      => $data['timezone'] ?? 'Africa/Nairobi',
                'currency'      => $data['currency'] ?? 'KES',
                'settings'      => $this->defaultSettings($data),
                'branding'      => [],
            ]);

            // 2. Create default limits based on plan
            $this->createLimits($tenant);

            // 3. Create admin user
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name'      => $data['admin_name'],
                'email'     => $data['admin_email'],
                'password'  => Hash::make($data['admin_password']),
            ]);
            $user->assignRole('admin');

            // 4. Seed default dunning policy
            $this->createDefaultDunningPolicy($tenant);

            // 5. Seed default SMS templates
            $this->createDefaultNotificationTemplates($tenant);

            return $tenant;
        });
    }

    private function defaultSettings(array $data): array
    {
        return [
            'invoice_prefix'     => strtoupper(substr($data['slug'], 0, 3)) . '-',
            'invoice_due_days'   => 7,
            'currency'           => $data['currency'] ?? 'KES',
            'sms_gateway'        => 'africas_talking',
            'mpesa_enabled'      => false,
            'billing_day'        => 1,
        ];
    }

    private function createLimits(Tenant $tenant): void
    {
        $limits = match ($tenant->plan) {
            'starter'    => ['max_clients' => 100, 'max_routers' => 2, 'max_sms_per_month' => 500, 'max_users' => 3],
            'growth'     => ['max_clients' => 500, 'max_routers' => 10, 'max_sms_per_month' => 5000, 'max_users' => 10],
            'enterprise' => ['max_clients' => 99999, 'max_routers' => 99999, 'max_sms_per_month' => 99999, 'max_users' => 99999],
            default      => ['max_clients' => 100, 'max_routers' => 2, 'max_sms_per_month' => 500, 'max_users' => 3],
        };

        TenantLimit::create(array_merge(['tenant_id' => $tenant->id], $limits));
    }

    private function createDefaultDunningPolicy(Tenant $tenant): void
    {
        DunningPolicy::create([
            'tenant_id'         => $tenant->id,
            'name'              => 'Default Policy',
            'is_default'        => true,
            'grace_period_days' => 3,
            'steps'             => [
                ['day_offset' => 1,  'action' => 'notify',   'channel' => ['sms', 'email'], 'template' => 'first_reminder'],
                ['day_offset' => 5,  'action' => 'notify',   'channel' => ['sms', 'email'], 'template' => 'second_reminder'],
                ['day_offset' => 10, 'action' => 'restrict_speed', 'speed_kbps' => 256, 'template' => 'speed_restricted'],
                ['day_offset' => 15, 'action' => 'suspend',  'channel' => ['sms', 'email'], 'template' => 'suspended'],
                ['day_offset' => 30, 'action' => 'terminate','channel' => ['sms', 'email'], 'template' => 'terminated'],
            ],
        ]);
    }

    private function createDefaultNotificationTemplates(Tenant $tenant): void
    {
        $templates = [
            ['event' => 'invoice.generated', 'channel' => 'sms', 'body' => 'Dear {{client_name}}, Invoice {{invoice_number}} of KES {{amount}} has been generated. Due: {{due_date}}. Pay via M-Pesa Paybill {{paybill}} Acc {{account}}.'],
            ['event' => 'payment.received',  'channel' => 'sms', 'body' => 'Dear {{client_name}}, payment of KES {{amount}} received on {{date}}. Thank you.'],
            ['event' => 'account.suspended', 'channel' => 'sms', 'body' => 'Dear {{client_name}}, your internet account has been suspended due to overdue invoice {{invoice_number}}. Pay KES {{amount}} to restore.'],
            ['event' => 'account.activated', 'channel' => 'sms', 'body' => 'Dear {{client_name}}, your internet account has been activated. Enjoy!'],
        ];

        foreach ($templates as $template) {
            \App\Models\NotificationTemplate::create(array_merge(
                ['tenant_id' => $tenant->id, 'is_active' => true],
                $template
            ));
        }
    }
}
```

### 7.6 Tenant Isolation Test (Critical — must pass before Phase 1 deploy)

```php
// tests/Feature/Tenancy/TenantIsolationTest.php
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Client;
use App\Models\Tenant;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_prevents_cross_tenant_access(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Create a client under tenant A
        $clientA = Client::factory()->create(['tenant_id' => $tenantA->id]);

        // Set tenant B as current tenant
        app()->instance('tenant', $tenantB);

        // Tenant B should not see Tenant A's client
        $this->assertNull(Client::find($clientA->id));
        $this->assertEquals(0, Client::count());
    }

    public function test_tenant_a_cannot_see_tenant_b_invoices(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        $clientA  = Client::factory()->create(['tenant_id' => $tenantA->id]);
        $invoiceA = \App\Models\Invoice::factory()->create([
            'tenant_id' => $tenantA->id,
            'client_id' => $clientA->id,
        ]);

        app()->instance('tenant', $tenantB);

        $this->assertEquals(0, \App\Models\Invoice::count());
        $this->assertNull(\App\Models\Invoice::find($invoiceA->id));
    }

    public function test_creating_model_auto_assigns_current_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app()->instance('tenant', $tenant);

        $client = Client::create([
            'name'  => 'Test Client',
            'phone' => '+254700000000',
            'email' => 'test@example.com',
        ]);

        $this->assertEquals($tenant->id, $client->tenant_id);
    }

    public function test_api_endpoint_requires_valid_tenant(): void
    {
        $response = $this->getJson('/api/v1/admin/clients', [
            'Host' => 'nonexistent.primebill.app',
        ]);

        $response->assertStatus(404)
                 ->assertJson(['code' => 'TENANT_NOT_FOUND']);
    }
}
```

---

## SECTION 8 — MODULE-BY-MODULE REFACTOR PLAN

### 8.1 SubscriptionService (Complete Rebuild)

```php
// app/Services/Billing/SubscriptionService.php
<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Jobs\GenerateInvoiceJob;
use App\Jobs\RenewSubscriptionJob;
use App\Events\SubscriptionRenewed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SubscriptionService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly ProrationService $prorationService,
    ) {}

    public function create(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::findOrFail($data['plan_id']);
            $now  = now();

            $subscription = Subscription::create([
                'client_id'            => $data['client_id'],
                'client_account_id'    => $data['client_account_id'],
                'plan_id'              => $data['plan_id'],
                'status'               => 'active',
                'billing_cycle'        => $data['billing_cycle'] ?? 'monthly',
                'cycle_day'            => $data['cycle_day'] ?? 1,
                'started_at'           => $now,
                'current_period_start' => $now,
                'current_period_end'   => $this->calcPeriodEnd($now, $data['billing_cycle'] ?? 'monthly'),
                'next_billing_at'      => $this->calcNextBilling($now, $data['billing_cycle'] ?? 'monthly'),
                'auto_renew'           => $data['auto_renew'] ?? true,
            ]);

            // Generate first invoice unless it's a trial
            if (!isset($data['trial_days'])) {
                GenerateInvoiceJob::dispatch(
                    app('tenant')->id,
                    $data['client_id'],
                    $subscription->id
                )->onQueue('billing');
            }

            return $subscription;
        });
    }

    public function renew(Subscription $subscription): void
    {
        if (!$subscription->isDueForRenewal()) {
            return;
        }

        DB::transaction(function () use ($subscription) {
            $now = now();

            $subscription->update([
                'current_period_start' => $now,
                'current_period_end'   => $this->calcPeriodEnd($now, $subscription->billing_cycle),
                'next_billing_at'      => $this->calcNextBilling($now, $subscription->billing_cycle),
            ]);

            GenerateInvoiceJob::dispatch(
                $subscription->tenant_id,
                $subscription->client_id,
                $subscription->id
            )->onQueue('billing');

            event(new SubscriptionRenewed($subscription));
        });
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, bool $immediate = false): void
    {
        DB::transaction(function () use ($subscription, $newPlan, $immediate) {
            $proratedCredit = 0;

            if ($immediate && $newPlan->price < $subscription->plan->price) {
                // Downgrade: calculate prorated credit
                $proratedCredit = $this->prorationService->calculateDowngradeCredit(
                    $subscription, $newPlan
                );
            } elseif ($immediate) {
                // Upgrade: generate prorated invoice for difference
                $proratedAmount = $this->prorationService->calculateUpgradeCharge(
                    $subscription, $newPlan
                );
                // Generate prorated invoice
            }

            SubscriptionChange::create([
                'subscription_id' => $subscription->id,
                'change_type'     => $newPlan->price > $subscription->plan->price ? 'upgrade' : 'downgrade',
                'from_plan_id'    => $subscription->plan_id,
                'to_plan_id'      => $newPlan->id,
                'effective_date'  => $immediate ? today() : $subscription->current_period_end,
                'prorated_credit' => $proratedCredit,
                'recorded_by'     => auth()->id(),
            ]);

            if ($immediate) {
                $subscription->update(['plan_id' => $newPlan->id]);
            }
        });
    }

    public function cancel(Subscription $subscription, string $reason = ''): void
    {
        $subscription->update([
            'status'        => 'cancelled',
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
            'auto_renew'    => false,
        ]);
    }

    private function calcPeriodEnd(Carbon $start, string $cycle): Carbon
    {
        return match ($cycle) {
            'monthly'   => $start->copy()->addMonth(),
            'quarterly' => $start->copy()->addMonths(3),
            'annual'    => $start->copy()->addYear(),
            default     => $start->copy()->addMonth(),
        };
    }

    private function calcNextBilling(Carbon $start, string $cycle): Carbon
    {
        return $this->calcPeriodEnd($start, $cycle);
    }
}
```

### 8.2 DunningService

```php
// app/Services/Billing/DunningService.php
<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\DunningPolicy;
use App\Models\DunningNotice;
use App\Jobs\ProcessDunningStepJob;
use Illuminate\Support\Facades\DB;

class DunningService
{
    public function runEngine(): void
    {
        // Find all overdue invoices that haven't been fully resolved
        $overdueInvoices = Invoice::where('status', 'overdue')
            ->with(['client.dunningPolicy', 'dunningNotices'])
            ->get();

        foreach ($overdueInvoices as $invoice) {
            $this->processInvoice($invoice);
        }
    }

    private function processInvoice(Invoice $invoice): void
    {
        $policy = $invoice->client->dunningPolicy
            ?? DunningPolicy::where('is_default', true)->first();

        if (!$policy) {
            return;
        }

        $daysOverdue = $invoice->due_date->diffInDays(now());
        $steps = collect($policy->steps);

        foreach ($steps as $index => $step) {
            $targetDay = $policy->grace_period_days + $step['day_offset'];

            if ($daysOverdue < $targetDay) {
                break; // Not yet due for this step
            }

            // Check if this step was already executed
            $alreadyExecuted = DunningNotice::where('invoice_id', $invoice->id)
                ->where('step_index', $index)
                ->where('status', 'sent')
                ->exists();

            if (!$alreadyExecuted) {
                ProcessDunningStepJob::dispatch(
                    $invoice->tenant_id,
                    $invoice->id,
                    $policy->id,
                    $index,
                    $step
                )->onQueue('billing');
            }
        }
    }
}
```

### 8.3 WalletService

```php
// app/Services/Billing/WalletService.php
<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\ClientWallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function getOrCreateWallet(Client $client): ClientWallet
    {
        return ClientWallet::firstOrCreate(
            ['tenant_id' => $client->tenant_id, 'client_id' => $client->id],
            ['balance' => 0.00, 'currency' => app('tenant')->currency ?? 'KES']
        );
    }

    public function credit(Client $client, float $amount, string $type, int $referenceId, string $referenceType, string $description = ''): WalletTransaction
    {
        return DB::transaction(function () use ($client, $amount, $type, $referenceId, $referenceType, $description) {
            $wallet = $this->getOrCreateWallet($client);

            $wallet->lockForUpdate()->find($wallet->id);
            $newBalance = $wallet->balance + $amount;

            $wallet->update(['balance' => $newBalance, 'last_activity' => now()]);

            return WalletTransaction::create([
                'tenant_id'       => $client->tenant_id,
                'wallet_id'       => $wallet->id,
                'client_id'       => $client->id,
                'type'            => 'credit',
                'amount'          => $amount,
                'running_balance' => $newBalance,
                'reference_type'  => $referenceType,
                'reference_id'    => $referenceId,
                'description'     => $description,
                'recorded_by'     => auth()->id(),
            ]);
        });
    }

    public function applyToInvoice(\App\Models\Invoice $invoice): float
    {
        $wallet = $this->getOrCreateWallet($invoice->client);

        if ($wallet->balance <= 0) {
            return 0;
        }

        $applyAmount = min($wallet->balance, $invoice->amount_due);

        DB::transaction(function () use ($wallet, $invoice, $applyAmount) {
            $newBalance = $wallet->balance - $applyAmount;

            $wallet->update(['balance' => $newBalance, 'last_activity' => now()]);

            WalletTransaction::create([
                'tenant_id'       => $invoice->tenant_id,
                'wallet_id'       => $wallet->id,
                'client_id'       => $invoice->client_id,
                'type'            => 'debit',
                'amount'          => $applyAmount,
                'running_balance' => $newBalance,
                'reference_type'  => 'invoice',
                'reference_id'    => $invoice->id,
                'description'     => "Applied to Invoice {$invoice->invoice_number}",
            ]);

            $invoice->decrement('amount_due', $applyAmount);

            if ($invoice->amount_due <= 0) {
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            }
        });

        return $applyAmount;
    }
}
```

### 8.4 FUP Engine (Phase 3)

```php
// app/Services/Fup/FupService.php
<?php

namespace App\Services\Fup;

use App\Models\ClientAccount;
use App\Models\ClientUsageCycle;
use App\Models\PlanFupTier;
use App\Jobs\SendCoaPacketJob;
use App\Jobs\UpdateMikrotikBandwidthJob;
use App\Jobs\SendSmsJob;

class FupService
{
    public function syncAndEnforce(ClientAccount $account): void
    {
        $cycle = ClientUsageCycle::where('client_account_id', $account->id)
            ->where('cycle_end', '>=', now())
            ->first();

        if (!$cycle) {
            return;
        }

        $totalMb    = ($cycle->bytes_downloaded + $cycle->bytes_uploaded) / 1024 / 1024;
        $plan       = $account->plan;
        $tiers      = PlanFupTier::where('plan_id', $plan->id)->orderBy('sequence')->get();

        if ($tiers->isEmpty()) {
            return; // No FUP configured for this plan
        }

        $currentTierIndex = 0;
        foreach ($tiers as $index => $tier) {
            if ($totalMb >= ($tier->data_threshold_mb / 1024)) {
                $currentTierIndex = $index + 1;
            }
        }

        if ($currentTierIndex !== $cycle->current_fup_tier) {
            $this->applyTierChange($account, $cycle, $currentTierIndex, $tiers);
        }
    }

    private function applyTierChange(ClientAccount $account, ClientUsageCycle $cycle, int $newTier, $tiers): void
    {
        $cycle->update(['current_fup_tier' => $newTier]);

        if ($newTier === 0) {
            // Restore full speed
            $plan = $account->plan;
            UpdateMikrotikBandwidthJob::dispatch(
                $account->tenant_id,
                $account->id,
                $plan->download_speed,
                $plan->upload_speed
            )->onQueue('provisioning');

            SendSmsJob::dispatch(
                $account->tenant_id,
                $account->client_id,
                "Your internet speed has been restored to full speed."
            )->onQueue('notifications');
        } else {
            $tier = $tiers[$newTier - 1];

            UpdateMikrotikBandwidthJob::dispatch(
                $account->tenant_id,
                $account->id,
                $tier->download_kbps,
                $tier->upload_kbps
            )->onQueue('provisioning');

            SendSmsJob::dispatch(
                $account->tenant_id,
                $account->client_id,
                "Your internet speed has been reduced due to Fair Usage Policy. You have used your data allocation."
            )->onQueue('notifications');
        }
    }
}
```

### 8.5 IPAM Service (Phase 3)

```php
// app/Services/Ipam/IpamService.php
<?php

namespace App\Services\Ipam;

use App\Models\IpPool;
use App\Models\IpAllocation;
use App\Models\IpAllocationHistory;
use App\Models\ClientAccount;
use Illuminate\Support\Facades\DB;

class IpamService
{
    public function allocate(ClientAccount $account, ?int $poolId = null): IpAllocation
    {
        return DB::transaction(function () use ($account, $poolId) {
            $pool = $poolId
                ? IpPool::lockForUpdate()->findOrFail($poolId)
                : IpPool::lockForUpdate()
                    ->where('is_active', true)
                    ->whereColumn('allocated_ips', '<', 'total_ips')
                    ->first();

            if (!$pool) {
                throw new \RuntimeException("No available IP pool for allocation");
            }

            // Find next free IP
            $allocation = IpAllocation::lockForUpdate()
                ->where('pool_id', $pool->id)
                ->whereNull('client_account_id')
                ->first();

            if (!$allocation) {
                throw new \RuntimeException("Pool {$pool->name} is exhausted");
            }

            $allocation->update([
                'client_account_id' => $account->id,
                'lease_type'        => 'static',
                'allocated_at'      => now(),
                'released_at'       => null,
            ]);

            $pool->increment('allocated_ips');

            IpAllocationHistory::create([
                'tenant_id'         => $account->tenant_id,
                'ip_address'        => $allocation->ip_address,
                'client_account_id' => $account->id,
                'allocated_at'      => now(),
            ]);

            return $allocation;
        });
    }

    public function release(ClientAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $allocation = IpAllocation::lockForUpdate()
                ->where('client_account_id', $account->id)
                ->first();

            if (!$allocation) {
                return;
            }

            IpAllocationHistory::where('client_account_id', $account->id)
                ->whereNull('released_at')
                ->update(['released_at' => now()]);

            $allocation->update([
                'client_account_id' => null,
                'released_at'       => now(),
            ]);

            IpPool::where('id', $allocation->pool_id)->decrement('allocated_ips');
        });
    }

    public function generatePoolAllocations(IpPool $pool): int
    {
        // Parse subnet and generate all host IPs
        [$network, $prefix] = explode('/', $pool->subnet);
        $totalHosts = pow(2, 32 - (int)$prefix) - 2; // Exclude network + broadcast
        $baseIp     = ip2long($network) + 1;

        $created = 0;
        for ($i = 0; $i < $totalHosts; $i++) {
            $ip = long2ip($baseIp + $i);
            IpAllocation::firstOrCreate([
                'tenant_id'  => $pool->tenant_id,
                'pool_id'    => $pool->id,
                'ip_address' => $ip,
            ], [
                'lease_type' => 'dynamic',
            ]);
            $created++;
        }

        $pool->update(['total_ips' => $created]);
        return $created;
    }
}
```

---

## SECTION 9 — API MIGRATION PLAN

### 9.1 Route Restructuring

```php
// routes/api.php — FULL RESTRUCTURE

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (no auth, no tenant)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {

    // Auth — high throttle to prevent brute force
    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('login',          [AuthController::class, 'login']);
        Route::post('register',       [TenantRegistrationController::class, 'register']);
        Route::post('forgot-password',[AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // M-Pesa Callbacks — high limit for Safaricom IPs
    Route::middleware(['validate.mpesa', 'throttle:webhooks'])->prefix('webhooks/mpesa')->group(function () {
        Route::post('stk',              [MpesaController::class, 'stkCallback']);
        Route::post('c2b/validate',     [MpesaController::class, 'c2bValidate']);
        Route::post('c2b/confirm',      [MpesaController::class, 'c2bConfirm']);
    });

    // RADIUS accounting
    Route::middleware('throttle:webhooks')->post('webhooks/radius/accounting', [RadiusController::class, 'accounting']);

    /*
    |----------------------------------------------------------------------
    | Admin API — authenticated, tenant-scoped
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'tenant.active', 'throttle:admin-api'])
         ->prefix('admin')
         ->group(function () {

        // Client management
        Route::apiResource('clients', ClientController::class);
        Route::post('clients/{client}/suspend',  [ClientController::class, 'suspend']);
        Route::post('clients/{client}/activate', [ClientController::class, 'activate']);
        Route::post('clients/{client}/accounts', [ClientAccountController::class, 'store']);

        // Billing
        Route::apiResource('invoices', InvoiceController::class);
        Route::post('invoices/bulk-generate', [InvoiceController::class, 'bulkGenerate']);
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);

        // Payments
        Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
        Route::post('mpesa/stk-push', [MpesaController::class, 'stkPush']);
        Route::get('payments/summary', [PaymentController::class, 'summary']);

        // Plans
        Route::apiResource('plans', PlanController::class);

        // Subscriptions (NEW)
        Route::apiResource('subscriptions', SubscriptionController::class);
        Route::post('subscriptions/{subscription}/renew',      [SubscriptionController::class, 'renew']);
        Route::post('subscriptions/{subscription}/cancel',     [SubscriptionController::class, 'cancel']);
        Route::post('subscriptions/{subscription}/change-plan',[SubscriptionController::class, 'changePlan']);

        // Network
        Route::apiResource('routers', RouterController::class);
        Route::apiResource('ip-pools', IpPoolController::class);        // NEW Phase 3
        Route::get('ip-pools/{pool}/allocations', [IpPoolController::class, 'allocations']);

        // Vouchers (NEW Phase 3)
        Route::apiResource('voucher-batches', VoucherBatchController::class);
        Route::get('voucher-batches/{batch}/vouchers', [VoucherController::class, 'index']);
        Route::post('voucher-batches/{batch}/export',  [VoucherController::class, 'export']);

        // Dunning (NEW Phase 2)
        Route::apiResource('dunning-policies', DunningPolicyController::class);
        Route::get('dunning/notices', [DunningNoticeController::class, 'index']);

        // Agents (NEW Phase 4)
        Route::apiResource('agents', AgentController::class);
        Route::get('agents/{agent}/commissions', [AgentController::class, 'commissions']);
        Route::post('agents/{agent}/payout',     [AgentController::class, 'payout']);

        // SMS
        Route::post('sms/send',       [SmsController::class, 'send']);
        Route::post('sms/bulk',       [SmsController::class, 'bulk']);
        Route::get('sms/logs',        [SmsController::class, 'logs']);
        Route::apiResource('sms/campaigns', SmsCampaignController::class); // NEW Phase 4

        // Tickets
        Route::apiResource('tickets', TicketController::class);
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply']);
        Route::post('tickets/{ticket}/close', [TicketController::class, 'close']);

        // Inventory
        Route::apiResource('inventory', InventoryController::class);

        // Finance
        Route::apiResource('expenditures', ExpenditureController::class);

        // Reports
        Route::post('reports/generate',          [ReportController::class, 'generate']);
        Route::get('reports/status/{jobId}',     [ReportController::class, 'status']);
        Route::get('reports/{type}/export',      [ReportController::class, 'export']);

        // Dashboard
        Route::get('dashboard/stats',            [DashboardController::class, 'stats']);
        Route::get('dashboard/traffic',          [DashboardController::class, 'traffic']);
        Route::get('dashboard/top-downloaders',  [DashboardController::class, 'topDownloaders']);

        // Settings
        Route::get('settings',                   [SettingsController::class, 'index']);
        Route::put('settings',                   [SettingsController::class, 'update']);

        // Audit
        Route::get('audit-logs',                 [AuditLogController::class, 'index']);

        // Webhooks (NEW Phase 4)
        Route::apiResource('webhooks', WebhookController::class);
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
        Route::post('webhooks/{webhook}/test',      [WebhookController::class, 'test']);
    });

    /*
    |----------------------------------------------------------------------
    | Client Portal — limited self-service
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'tenant.active', 'throttle:admin-api'])
         ->prefix('portal')
         ->group(function () {
        Route::get('dashboard',     [PortalDashboardController::class, 'index']);
        Route::get('invoices',      [PortalInvoiceController::class, 'index']);
        Route::get('invoices/{id}', [PortalInvoiceController::class, 'show']);
        Route::post('invoices/{id}/pay', [PortalPaymentController::class, 'initiatePay']);
        Route::get('usage',         [PortalDashboardController::class, 'usage']);
        Route::apiResource('tickets', PortalTicketController::class)->only(['index', 'store', 'show']);
        Route::get('profile',       [PortalProfileController::class, 'show']);
        Route::put('profile',       [PortalProfileController::class, 'update']);
    });
});

/*
|--------------------------------------------------------------------------
| Platform Admin (super admin — no tenant scope)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:platform_admin'])
     ->prefix('v1/platform')
     ->group(function () {
    Route::apiResource('tenants', Platform\TenantController::class);
    Route::post('tenants/{tenant}/impersonate',  [Platform\ImpersonationController::class, 'impersonate']);
    Route::delete('tenants/{tenant}/impersonate',[Platform\ImpersonationController::class, 'stop']);
    Route::apiResource('platform-plans', Platform\PlatformPlanController::class);
    Route::get('analytics', [Platform\AnalyticsController::class, 'index']);
});
```

### 9.2 Rate Limiters (complete definition)

```php
// app/Providers/RouteServiceProvider.php — in boot()

RateLimiter::for('admin-api', fn($req) =>
    Limit::perMinute(120)
         ->by(optional(app('tenant'))->id . '|' . optional($req->user())->id)
         ->response(fn() => response()->json(['success' => false, 'message' => 'Too many requests', 'code' => 'RATE_LIMITED'], 429))
);

RateLimiter::for('auth', fn($req) =>
    Limit::perMinute(10)->by($req->ip())
);

RateLimiter::for('exports', fn($req) =>
    Limit::perHour(20)->by(optional(app('tenant'))->id ?? $req->ip())
);

RateLimiter::for('webhooks', fn($req) =>
    Limit::perMinute(300)->by($req->ip())  // High limit for Safaricom IPs
);
```

---

## SECTION 10 — PERFORMANCE & SCALABILITY PLAN

### 10.1 Laravel Horizon Configuration

```php
// config/horizon.php
<?php

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => 'horizon',
    'use'    => 'default',
    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue'      => ['critical', 'provisioning', 'billing', 'notifications', 'fup', 'webhooks', 'reports', 'default'],
            'balance'    => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'tries'  => 3,
            'timeout' => 60,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-critical' => [
                'connection' => 'redis',
                'queue'      => ['critical'],
                'balance'    => 'simple',
                'processes'  => 4,
                'tries'      => 3,
                'timeout'    => 30,
            ],
            'supervisor-provisioning' => [
                'connection' => 'redis',
                'queue'      => ['provisioning'],
                'balance'    => 'simple',
                'processes'  => 4,
                'tries'      => 5,
                'timeout'    => 60,
            ],
            'supervisor-billing' => [
                'connection' => 'redis',
                'queue'      => ['billing'],
                'balance'    => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 8,
                'tries'      => 3,
                'timeout'    => 120,
            ],
            'supervisor-notifications' => [
                'connection' => 'redis',
                'queue'      => ['notifications'],
                'balance'    => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 8,
                'tries'      => 5,
                'timeout'    => 30,
            ],
            'supervisor-fup' => [
                'connection' => 'redis',
                'queue'      => ['fup'],
                'balance'    => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries'      => 1,
                'timeout'    => 30,
            ],
            'supervisor-default' => [
                'connection' => 'redis',
                'queue'      => ['webhooks', 'reports', 'default'],
                'balance'    => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries'      => 3,
                'timeout'    => 300,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue'      => ['default'],
                'balance'    => 'simple',
                'processes'  => 2,
                'tries'      => 3,
            ],
        ],
    ],
];
```

### 10.2 Scheduled Commands (Complete — routes/console.php)

```php
// routes/console.php
<?php

use Illuminate\Support\Facades\Schedule;

// Billing
Schedule::job(\App\Jobs\GenerateMonthlyInvoicesJob::class)
    ->monthlyOn(1, '08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('generate-monthly-invoices')
    ->emailOutputOnFailure(config('mail.admin_address'));

Schedule::job(\App\Jobs\RenewSubscriptionsJob::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('renew-subscriptions');

// Dunning
Schedule::job(\App\Jobs\RunDunningEngineJob::class)
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('run-dunning-engine');

// Reactivation (check every 15 min for paid accounts to restore)
Schedule::job(\App\Jobs\ReactivatePaidAccountsJob::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('reactivate-paid-accounts');

// FUP
Schedule::job(\App\Jobs\SyncFupUsageJob::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('sync-fup-usage');

Schedule::job(\App\Jobs\ResetFupCyclesJob::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('reset-fup-cycles');

// Network
Schedule::job(\App\Jobs\PollRouterTrafficJob::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('poll-router-traffic');

Schedule::job(\App\Jobs\SyncRadiusAccountingJob::class)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('sync-radius-accounting');

// M-Pesa
Schedule::job(\App\Jobs\ReconcileMpesaPaymentsJob::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('reconcile-mpesa');

// Notifications
Schedule::job(\App\Jobs\SendInvoiceRemindersJob::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('send-invoice-reminders');

// Vouchers
Schedule::job(\App\Jobs\ExpireVouchersJob::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('expire-vouchers');

// SLA
Schedule::job(\App\Jobs\GenerateSlaReportsJob::class)
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('generate-sla-reports');

// Maintenance
Schedule::job(\App\Jobs\CleanOldLogsJob::class)
    ->weekly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('clean-old-logs');

Schedule::job(\App\Jobs\PruneSoftDeletedRecordsJob::class)
    ->monthly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('prune-soft-deleted');
```

---

## SECTION 11 — SECURITY HARDENING PLAN

### 11.1 M-Pesa Race Condition Fix

```php
// app/Services/Mpesa/MpesaService.php
// Replace the STK callback handler:

public function handleStkCallback(array $payload): void
{
    $checkoutRequestId = $payload['Body']['stkCallback']['CheckoutRequestID'];

    DB::transaction(function () use ($payload, $checkoutRequestId) {
        // Pessimistic lock prevents concurrent processing of same transaction
        $transaction = MpesaTransaction::lockForUpdate()
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();

        if (!$transaction) {
            Log::warning("M-Pesa STK callback for unknown checkout: {$checkoutRequestId}");
            return;
        }

        // Idempotency check — already processed
        if ($transaction->status === 'completed') {
            Log::info("M-Pesa STK callback already processed: {$checkoutRequestId}");
            return;
        }

        $resultCode = $payload['Body']['stkCallback']['ResultCode'];

        if ($resultCode === 0) {
            // Success — extract metadata
            $items = collect($payload['Body']['stkCallback']['CallbackMetadata']['Item']);
            $amount    = $items->firstWhere('Name', 'Amount')['Value'];
            $mpesaRef  = $items->firstWhere('Name', 'MpesaReceiptNumber')['Value'];
            $phone     = $items->firstWhere('Name', 'PhoneNumber')['Value'];

            $transaction->update([
                'status'     => 'completed',
                'amount'     => $amount,
                'mpesa_ref'  => $mpesaRef,
                'phone'      => $phone,
                'payload'    => $payload,
            ]);

            // Dispatch payment recording (separate job for safety)
            ProcessMpesaCallbackJob::dispatch(
                $transaction->tenant_id,
                $transaction->id
            )->onQueue('critical');
        } else {
            $transaction->update([
                'status'        => 'failed',
                'result_code'   => $resultCode,
                'result_desc'   => $payload['Body']['stkCallback']['ResultDesc'],
            ]);
        }
    });
}
```

### 11.2 Encrypted Tenant Credentials

```php
// app/Services/Tenancy/TenantCredentialService.php
<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class TenantCredentialService
{
    private array $encryptedKeys = [
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_passkey',
        'at_api_key',
        'sms_api_key',
        'stripe_secret_key',
    ];

    public function store(Tenant $tenant, string $key, string $value): void
    {
        $settings = $tenant->settings ?? [];

        if (in_array($key, $this->encryptedKeys)) {
            $settings[$key] = Crypt::encryptString($value);
        } else {
            $settings[$key] = $value;
        }

        $tenant->update(['settings' => $settings]);
    }

    public function get(Tenant $tenant, string $key): ?string
    {
        $value = $tenant->getSetting($key);

        if ($value === null) {
            return null;
        }

        if (in_array($key, $this->encryptedKeys)) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception) {
                return null; // Tampered or old format
            }
        }

        return $value;
    }
}
```

### 11.3 Webhook Security

```php
// app/Services/Webhook/WebhookService.php
<?php

namespace App\Services\Webhook;

use App\Models\WebhookEndpoint;
use App\Models\WebhookDelivery;
use App\Jobs\DeliverWebhookJob;

class WebhookService
{
    public function dispatch(string $event, array $payload): void
    {
        $endpoints = WebhookEndpoint::where('is_active', true)
            ->whereJsonContains('events', $event)
            ->get();

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'tenant_id'   => $endpoint->tenant_id,
                'endpoint_id' => $endpoint->id,
                'event_type'  => $event,
                'payload'     => $payload,
                'status'      => 'pending',
            ]);

            DeliverWebhookJob::dispatch($endpoint->tenant_id, $delivery->id)
                ->onQueue('webhooks')
                ->delay(now()->addSeconds(5));
        }
    }
}
```

```php
// app/Jobs/DeliverWebhookJob.php
<?php

namespace App\Jobs;

use App\Jobs\Concerns\HasTenantContext;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, HasTenantContext;

    public int $tries   = 10;
    public int $timeout = 30;

    public function __construct(
        public int $tenantId,
        public int $deliveryId,
    ) {}

    public function handle(): void
    {
        $this->setTenantContext();

        $delivery = WebhookDelivery::findOrFail($this->deliveryId);
        $endpoint = WebhookEndpoint::findOrFail($delivery->endpoint_id);

        // Build HMAC signature
        $body      = json_encode($delivery->payload);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type'         => 'application/json',
                    'X-PrimeBill-Event'    => $delivery->event_type,
                    'X-PrimeBill-Delivery' => $delivery->id,
                    'X-PrimeBill-Signature'=> "sha256={$signature}",
                ])
                ->post($endpoint->url, $delivery->payload);

            $delivery->update([
                'status'          => $response->successful() ? 'delivered' : 'failed',
                'response_status' => $response->status(),
                'response_body'   => substr($response->body(), 0, 500),
                'attempt_count'   => $delivery->attempt_count + 1,
                'delivered_at'    => $response->successful() ? now() : null,
            ]);

            if ($response->successful()) {
                $endpoint->update(['last_success_at' => now(), 'failure_count' => 0]);
            } else {
                $endpoint->increment('failure_count');
                if ($endpoint->failure_count >= 50) {
                    $endpoint->update(['is_active' => false]);
                    Log::warning("Webhook endpoint {$endpoint->id} disabled after 50 failures");
                }
                $this->release($this->backoff()[$this->attempts() - 1] ?? 3600);
            }
        } catch (\Exception $e) {
            $delivery->update([
                'status'        => 'failed',
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            $this->release($this->backoff()[$this->attempts() - 1] ?? 3600);
        }
    }

    public function backoff(): array
    {
        // Exponential backoff: 1min, 5min, 10min, 30min, 1hr, 2hr, 4hr, 8hr, 16hr, 24hr
        return [60, 300, 600, 1800, 3600, 7200, 14400, 28800, 57600, 86400];
    }
}
```

---

## SECTION 12 — DEVOPS & INFRASTRUCTURE PLAN

### 12.1 Docker Setup

```dockerfile
# docker/Dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    git curl libpng-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring zip gd opcache pcntl

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```

```yaml
# docker/docker-compose.yml
version: '3.8'

services:
  app:
    build: ./docker
    volumes:
      - .:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/php.ini
    networks: [primebill]
    depends_on: [mysql, redis]
    environment:
      - APP_ENV=${APP_ENV}

  nginx:
    image: nginx:1.25-alpine
    ports: ['80:80', '443:443']
    volumes:
      - .:/var/www/html
      - ./docker/nginx/primebill.conf:/etc/nginx/conf.d/default.conf
      - ./docker/nginx/ssl:/etc/nginx/ssl
    networks: [primebill]
    depends_on: [app]

  queue:
    build: ./docker
    command: php artisan horizon
    volumes:
      - .:/var/www/html
    networks: [primebill]
    depends_on: [mysql, redis]
    restart: unless-stopped

  scheduler:
    build: ./docker
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
    volumes:
      - .:/var/www/html
    networks: [primebill]
    depends_on: [mysql, redis]
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf
    networks: [primebill]
    ports: ['3306:3306']

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}
    volumes: [redis_data:/data]
    networks: [primebill]
    ports: ['6379:6379']

volumes:
  mysql_data:
  redis_data:

networks:
  primebill:
    driver: bridge
```

```nginx
# docker/nginx/primebill.conf
server {
    listen 80;
    server_name *.primebill.app;
    root /var/www/html/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }

    # Block access to .env and sensitive files
    location ~ /\.(env|git|htaccess) {
        deny all;
    }

    access_log /var/log/nginx/primebill_access.log;
    error_log  /var/log/nginx/primebill_error.log;
}
```

```ini
# docker/supervisor/horizon.conf
[program:horizon]
command=php /var/www/html/artisan horizon
directory=/var/www/html
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/horizon.log
stopwaitsecs=3600
```

### 12.2 GitHub Actions CI/CD

```yaml
# .github/workflows/tests.yml
name: Tests

on:
  push:
    branches: [develop, main]
  pull_request:
    branches: [develop, main]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: primebill_test
        ports: ['3306:3306']
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

      redis:
        image: redis:7-alpine
        ports: ['6379:6379']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, pdo_mysql, redis, zip
          coverage: xdebug

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Copy .env.testing
        run: cp .env.testing.example .env.testing

      - name: Generate key
        run: php artisan key:generate --env=testing

      - name: Run migrations
        run: php artisan migrate --env=testing --force

      - name: Run tests
        run: php artisan test --env=testing --coverage --min=80

      - name: PHPStan static analysis
        run: vendor/bin/phpstan analyse --level=6

  security-scan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Security audit
        run: composer audit
```

```yaml
# .github/workflows/deploy-production.yml
name: Deploy Production

on:
  push:
    tags: ['v*.*.*']

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: production

    steps:
      - uses: actions/checkout@v4

      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.PROD_HOST }}
          username: ${{ secrets.PROD_USER }}
          key: ${{ secrets.PROD_SSH_KEY }}
          script: |
            cd /var/www/primebill
            git pull origin main
            composer install --no-dev --optimize-autoloader
            php artisan down --retry=60
            php artisan migrate --force
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
            php artisan event:cache
            php artisan up
            sudo supervisorctl restart horizon
```

---

## SECTION 13 — TESTING & QA STRATEGY

### 13.1 Critical Tests to Write Before Any Deploy

```php
// tests/Feature/Security/MpesaCallbackSecurityTest.php
<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class MpesaCallbackSecurityTest extends TestCase
{
    public function test_mpesa_callback_rejected_without_signature(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mpesa/stk', []);
        $response->assertStatus(401);
    }

    public function test_mpesa_callback_rejected_with_wrong_signature(): void
    {
        config(['services.mpesa.callback_secret' => 'real_secret']);

        $response = $this->postJson('/api/v1/webhooks/mpesa/stk', [], [
            'X-Safaricom-Signature' => 'wrong_signature',
        ]);

        $response->assertStatus(401);
    }

    public function test_mpesa_callback_accepted_with_valid_hmac(): void
    {
        $secret  = 'test_secret';
        $payload = json_encode(['test' => true]);

        config(['services.mpesa.callback_secret' => $secret]);

        $signature = hash_hmac('sha256', $payload, $secret);

        $response = $this->withHeaders(['X-Safaricom-Signature' => $signature])
            ->postJson('/api/v1/webhooks/mpesa/stk', ['test' => true]);

        // Should not be 401 (may be 422 for invalid structure, that's fine)
        $response->assertStatus(200);
    }
}
```

```php
// tests/Feature/Billing/SubscriptionTest.php
<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_creates_with_correct_billing_dates(): void
    {
        $tenant = \App\Models\Tenant::factory()->create(['status' => 'active']);
        app()->instance('tenant', $tenant);

        $client  = Client::factory()->create(['tenant_id' => $tenant->id]);
        $account = \App\Models\ClientAccount::factory()->create(['client_id' => $client->id, 'tenant_id' => $tenant->id]);
        $plan    = Plan::factory()->create(['tenant_id' => $tenant->id]);

        $service = app(SubscriptionService::class);
        $sub = $service->create([
            'client_id'         => $client->id,
            'client_account_id' => $account->id,
            'plan_id'           => $plan->id,
            'billing_cycle'     => 'monthly',
        ]);

        $this->assertEquals('active', $sub->status);
        $this->assertNotNull($sub->next_billing_at);
        $this->assertTrue($sub->next_billing_at->gt(now()));
    }

    public function test_subscription_renewal_generates_invoice(): void
    {
        // Test that RenewSubscriptionJob dispatches GenerateInvoiceJob
        \Illuminate\Support\Facades\Queue::fake();

        $sub = Subscription::factory()->create([
            'status'         => 'active',
            'auto_renew'     => true,
            'next_billing_at'=> now()->subDay(), // overdue for renewal
        ]);

        app(SubscriptionService::class)->renew($sub);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateInvoiceJob::class);
    }
}
```

### 13.2 Tenancy Isolation Test (must pass with 100% — zero tolerance)

```php
// tests/Feature/Tenancy/GlobalScopeTest.php

// Test EVERY model that has BelongsToTenant trait
public function test_all_tenant_models_are_isolated(): void
{
    $tenantA = Tenant::factory()->create(['status' => 'active']);
    $tenantB = Tenant::factory()->create(['status' => 'active']);

    $models = [
        \App\Models\Client::class,
        \App\Models\Invoice::class,
        \App\Models\Payment::class,
        \App\Models\Plan::class,
        \App\Models\Router::class,
        \App\Models\Ticket::class,
    ];

    foreach ($models as $modelClass) {
        // Create record under tenant A
        $record = $modelClass::factory()->create(['tenant_id' => $tenantA->id]);

        // Switch to tenant B
        app()->instance('tenant', $tenantB);

        // Must not be visible
        $this->assertNull($modelClass::find($record->id), 
            "Model {$modelClass} is NOT isolated! Tenant B can see Tenant A's data.");

        // Switch back for next iteration
        app()->instance('tenant', $tenantA);
    }
}
```

---

## SECTION 14 — AUTOMATION SCRIPTS

### scripts/migrate-phase-0.sh

```bash
#!/bin/bash
# scripts/migrate-phase-0.sh
# Run Phase 0 migrations — all online-safe, no downtime required

set -e

echo "=== PrimeBill Phase 0 Migration ==="
echo "Running security fixes and performance indexes..."

# Verify environment
if [ "$APP_ENV" = "production" ]; then
    echo "PRODUCTION environment detected. Proceeding with caution..."
    read -p "Type 'migrate' to confirm: " confirm
    if [ "$confirm" != "migrate" ]; then
        echo "Aborted."
        exit 1
    fi
fi

# Run Phase 0 migrations
php artisan migrate --path=database/migrations/2026_05_01_000001_add_missing_indexes.php --force
echo "✓ Missing indexes added"

php artisan migrate --path=database/migrations/2026_05_01_000002_fix_orphaned_migration_references.php --force
echo "✓ Orphaned migration references fixed"

# Clear caches
php artisan cache:clear
php artisan config:cache
php artisan route:cache

echo "=== Phase 0 complete ==="
```

### scripts/backfill-tenant-ids.sh

```bash
#!/bin/bash
# scripts/backfill-tenant-ids.sh
# Backfill tenant_id on all existing data
# Run AFTER: Phase 1 tenant_id columns added
# Run BEFORE: NOT NULL constraint migration

set -e

echo "=== Tenant ID Backfill ==="

# Verify all tenant_id columns exist before backfill
php artisan tinker --execute="
    \$tables = ['users', 'clients', 'invoices', 'payments', 'client_accounts'];
    foreach (\$tables as \$table) {
        if (!Schema::hasColumn(\$table, 'tenant_id')) {
            throw new Exception('tenant_id missing from ' . \$table . ' — run add_tenant_id migrations first');
        }
    }
    echo 'All tenant_id columns verified.' . PHP_EOL;
"

# Run backfill migration
php artisan migrate --path=database/migrations/2026_05_10_000020_backfill_tenant_id_existing_data.php --force
echo "✓ Backfill complete"

# Verify no NULLs remain
php artisan tinker --execute="
    \$tables = ['users', 'clients', 'invoices', 'payments', 'client_accounts', 'plans', 'routers'];
    foreach (\$tables as \$table) {
        \$count = DB::table(\$table)->whereNull('tenant_id')->count();
        if (\$count > 0) {
            throw new Exception(\$count . ' NULL tenant_id rows in ' . \$table);
        }
        echo '✓ ' . \$table . ': 0 NULL tenant_id rows' . PHP_EOL;
    }
"

echo "=== Backfill verified — safe to run NOT NULL migration ==="
```

### scripts/cache-warm.sh

```bash
#!/bin/bash
# scripts/cache-warm.sh
# Warm caches after deployment

set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Warm dashboard cache for all active tenants
php artisan tinker --execute="
    App\Models\Tenant::where('status', 'active')->each(function(\$tenant) {
        app()->instance('tenant', \$tenant);
        app(App\Services\Dashboard\DashboardService::class)->getStats();
        echo 'Warmed cache for tenant: ' . \$tenant->slug . PHP_EOL;
    });
"

echo "=== Cache warm complete ==="
```

---

## SECTION 15 — FEATURE FLAG STRATEGY

```php
// app/Http/Middleware/RequireFeatureFlag.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireFeatureFlag
{
    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $tenant = app('tenant');

        if (!$tenant || !$tenant->getSetting("features.{$feature}", false)) {
            return response()->json([
                'success' => false,
                'message' => 'This feature is not enabled for your account.',
                'code'    => 'FEATURE_DISABLED',
            ], 403);
        }

        return $next($request);
    }
}
```

**Feature flag gates in routes:**

```php
// Route requiring hotspot feature:
Route::middleware(['auth:sanctum', 'feature:hotspot'])
    ->prefix('admin/vouchers')
    ->group(function () { ... });

// Route requiring webhook feature:
Route::middleware(['auth:sanctum', 'feature:webhooks'])
    ->prefix('admin/webhooks')
    ->group(function () { ... });
```

**Enable/disable per tenant (platform admin):**

```php
$tenant->update(['settings' => array_merge($tenant->settings, [
    'features' => [
        'hotspot'   => true,
        'webhooks'  => true,
        'fup'       => true,
        'white_label' => false,
    ]
])]);
```

---

## SECTION 16 — OBSERVABILITY & MONITORING

### 16.1 Structured Logging

```php
// app/Providers/AppServiceProvider.php — in boot()

Log::channel('daily')->info('request.completed', [
    'tenant_id'   => optional(app('tenant'))->id,
    'user_id'     => optional(auth()->user())->id,
    'method'      => request()->method(),
    'path'        => request()->path(),
    'status'      => http_response_code(),
    'duration_ms' => round((microtime(true) - LARAVEL_START) * 1000),
    'memory_mb'   => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
]);
```

### 16.2 Sentry Integration

```php
// config/sentry.php
'dsn' => env('SENTRY_LARAVEL_DSN'),
'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
    // Attach tenant context to every error
    $tenant = app('tenant');
    if ($tenant) {
        $event->setContext('tenant', [
            'id'   => $tenant->id,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
        ]);
    }
    return $event;
},
```

---

## SECTION 17 — FINAL CUTOVER PLAN

### 17.1 Pre-Cutover Checklist

```
[ ] All Phase 0 PRs merged and tests passing
[ ] All Phase 1 PRs merged and tests passing (especially TenantIsolationTest)
[ ] Backfill verification queries show 0 NULL tenant_id rows
[ ] NOT NULL constraint migration applied successfully
[ ] Redis tagged cache confirmed working (test with Cache::tags())
[ ] M-Pesa HMAC confirmed mandatory (test 401 on unsigned callback)
[ ] StorePaymentRequest::authorize() confirmed enforcing permissions
[ ] SubscriptionService unit tests passing
[ ] DashboardService cache TTL verified (10 minutes)
[ ] Horizon dashboard accessible and all queues draining
[ ] All scheduled jobs registered with .onOneServer()
[ ] No null reference errors from BelongsToTenant trait (smoke test each model)
[ ] Tenant registration flow tested end-to-end
[ ] Default dunning policy seeded for default tenant
[ ] Read replica confirmed receiving replication lag < 100ms
```

### 17.2 Live Cutover Steps

```bash
# 1. Enable maintenance mode with retry
php artisan down --retry=60 --secret=CUTOVER_TOKEN

# 2. Final database migrations (only if any pending)
php artisan migrate --force

# 3. Clear and rebuild all caches
php artisan optimize:clear
php artisan optimize

# 4. Restart queue workers
sudo supervisorctl restart horizon

# 5. Bring app back up
php artisan up

# 6. Run smoke tests
php artisan test --filter=SmokeTest --env=production
```

### 17.3 Rollback Triggers

Immediately roll back if ANY of:
- Tenant isolation test failures in production smoke test
- M-Pesa callback returning 500 errors
- Payment recording failing for any tenant
- Dashboard returning 500 for any authenticated user
- Queue worker crash loop in Horizon

```bash
# Rollback command
php artisan down
php artisan migrate:rollback --step=<n>   # n = number of migrations in this phase
php artisan optimize:clear
php artisan up
```

### 17.4 Post-Cutover Validation (30-minute window)

```
[ ] All tenants can log in
[ ] Dashboard stats loading (check cache hit rate in Redis)
[ ] Invoice listing returns correct data (no cross-tenant leakage)
[ ] M-Pesa STK push initiates successfully
[ ] M-Pesa callback processes correctly (test with sandbox)
[ ] SMS sending functional (send test SMS)
[ ] Queue jobs processing (check Horizon dashboard)
[ ] Scheduled jobs showing in scheduler list (php artisan schedule:list)
[ ] Sentry not showing elevated error rate
[ ] No full-table-scan queries in MySQL slow query log (check for missing tenant_id in WHERE)
```

---

## APPENDIX — QUICK REFERENCE

### Critical File Changes Summary (Phase 0 — immediate)

| File | Change | Risk |
|---|---|---|
| `ValidateMpesaCallback.php` | Make HMAC non-optional | ZERO — security hardening |
| `StorePaymentRequest.php` | Fix authorize() | ZERO — security hardening |
| `ClientAccountController.php` | Password min 8 | ZERO — tighten validation |
| `DashboardService.php` | Add cache::tags | LOW — additive caching |
| `InvoiceService.php` | Add eager loading | ZERO — no behavior change |
| `DashboardService.php` | Fix N+1 | ZERO — no behavior change |
| `ReportService.php` | DB aggregation | LOW — same data, faster |
| `routes/api.php` | Add rate limits on exports | ZERO |
| `ApiResponse.php` | New trait (add to controllers) | LOW — additive |
| `PollRouterTraffic.php` | Implement handle() | LOW — was empty |
| `2026_05_01_000001_add_missing_indexes.php` | Add indexes | ZERO — additive, online-safe |

### Composer Packages to Add

```bash
# Phase 1
composer require laravel/horizon

# Phase 2
composer require owen-it/laravel-auditing   # or custom audit observer

# Phase 3  
# MikroTik API client already in codebase

# Optional
composer require spatie/laravel-query-builder  # for filterable API endpoints
```

### Environment Variables to Add

```dotenv
# Tenancy
APP_DOMAIN=primebill.app

# Horizon
HORIZON_DOMAIN=
HORIZON_PREFIX=horizon:

# Per-tenant M-Pesa stored in DB (encrypted), not .env
# Platform-level M-Pesa sandbox for testing:
MPESA_CALLBACK_SECRET=   # MUST be set — never empty

# Read replica
DB_READ_HOST=

# Sentry
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
```