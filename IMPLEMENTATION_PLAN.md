# PrimeBill OSS/BSS Production Blueprint — Implementation Plan

**Date:** August 2026  
**Status:** Active Implementation  
**Target:** Production-ready SaaS multi-tenant ISP billing platform

---

## Executive Summary

This document provides the actionable implementation plan for transforming PrimeBill from its current partial implementation into a production-ready SaaS multi-tenant ISP billing platform. The plan follows a strict phased approach with clear priorities and deliverables.

**Current State Analysis:**
- ✅ **Complete:** Tenant model, BelongsToTenant trait, ResolveTenant middleware, TenantResolver service
- ✅ **Complete:** Basic multi-tenancy migration (users, clients, plans, invoices, payments, routers)
- ⚠️ **Partial:** Subscription management (model exists but service broken)
- ❌ **Missing:** Complete tenant_id coverage, critical bug fixes, security hardening
- ❌ **Missing:** Dunning, FUP engine, IPAM, voucher system, wallet/credits

**Strategy:** Foundation Fixes (P1) → Core SaaS (P2) → Network (P3) → Ecosystem (P4)

---

## Phase 1 — Foundation Fixes (Weeks 1-4) 🔴 CRITICAL

**Goal:** Fix critical bugs, security vulnerabilities, and complete multi-tenancy foundation.

### 1.1 Complete Multi-Tenancy Implementation

#### Task 1.1.1: Extend tenant_id to All Remaining Models
**Priority:** P0  
**Estimated Time:** 4 hours

Create migration to add `tenant_id` to all tenant-scoped tables:
- `tickets`, `ticket_replies`
- `sms_logs`
- `expenditures`
- `inventory_items`
- `network_traffic`
- `radius_sessions`
- `sales_commissions`
- `fup_logs`
- `notifications`
- `ledger_entries`
- `idempotency_keys`
- `mpesa_transactions` (verify exists)
- `loyalty_points`
- `products`
- `leads`
- `prospects`
- `subscription_plans`
- `tenant_subscriptions`
- `subscription_invoices`
- `customer_subscriptions`
- `vouchers`

**File:** `database/migrations/2026_08_06_000001_extend_tenant_id_to_all_models.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'tickets', 'ticket_replies', 'sms_logs', 'expenditures',
            'inventory_items', 'network_traffic', 'radius_sessions',
            'sales_commissions', 'fup_logs', 'notifications', 'ledger_entries',
            'idempotency_keys', 'loyalty_points', 'products', 'leads',
            'prospects', 'subscription_plans', 'tenant_subscriptions',
            'subscription_invoices', 'customer_subscriptions', 'vouchers'
        ];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'tickets', 'ticket_replies', 'sms_logs', 'expenditures',
            'inventory_items', 'network_traffic', 'radius_sessions',
            'sales_commissions', 'fup_logs', 'notifications', 'ledger_entries',
            'idempotency_keys', 'loyalty_points', 'products', 'leads',
            'prospects', 'subscription_plans', 'tenant_subscriptions',
            'subscription_invoices', 'customer_subscriptions', 'vouchers'
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                if (Schema::hasColumn($table, 'tenant_id')) {
                    $table->dropColumn('tenant_id');
                }
            });
        }
    }
};
```

#### Task 1.1.2: Apply BelongsToTenant Trait to All Models
**Priority:** P0  
**Estimated Time:** 2 hours

Add `use BelongsToTenant;` to all tenant-scoped models:
- `Client`, `Plan`, `Invoice`, `Payment`, `Router`
- `Ticket`, `TicketReply`, `SmsLog`, `Expenditure`
- `InventoryItem`, `NetworkTraffic`, `RadiusSession`
- `SalesCommission`, `FupLog`, `Notification`
- `LedgerEntry`, `IdempotencyKey`, `LoyaltyPoints`
- `Product`, `Lead`, `Prospect`
- `SubscriptionPlan`, `TenantSubscription`, `SubscriptionInvoice`
- `CustomerSubscription`, `Voucher`

**Files to modify:**
- `app/Models/Client.php`
- `app/Models/Plan.php`
- `app/Models/Invoice.php`
- `app/Models/Payment.php`
- `app/Models/Router.php`
- `app/Models/Ticket.php`
- `app/Models/TicketReply.php`
- `app/Models/SmsLog.php`
- `app/Models/Expenditure.php`
- `app/Models/InventoryItem.php`
- `app/Models/NetworkTraffic.php`
- `app/Models/RadiusSession.php`
- `app/Models/SalesCommission.php`
- `app/Models/FupLog.php`
- `app/Models/Notification.php`
- `app/Models/LedgerEntry.php`
- `app/Models/IdempotencyKey.php`
- `app/Models/LoyaltyPoints.php`
- `app/Models/Product.php`
- `app/Models/Lead.php`
- `app/Models/Prospect.php`
- `app/Models/SubscriptionPlan.php`
- `app/Models/TenantSubscription.php`
- `app/Models/SubscriptionInvoice.php`
- `app/Models/CustomerSubscription.php`
- `app/Models/Voucher.php`

Example for `Client.php`:
```php
class Client extends Model
{
    use BelongsToTenant; // ADD THIS LINE
    
    // ... rest of model
}
```

#### Task 1.1.3: Create Tenant Isolation Tests
**Priority:** P0  
**Estimated Time:** 3 hours

**File:** `tests/Feature/TenantIsolationTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Client;
use App\Models\Invoice;

class TenantIsolationTest extends TestCase
{
    /** @test */
    public function global_scope_prevents_cross_tenant_access(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        
        $clientA = Client::factory()->for($tenantA)->create();
        
        // Switch to tenant B context
        app()->instance('currentTenant', $tenantB);
        
        // Client from tenant A should not be visible
        $this->assertNull(Client::find($clientA->id));
    }
    
    /** @test */
    public function user_cannot_access_other_tenant_data(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        
        $userA = User::factory()->for($tenantA)->create();
        $clientB = Client::factory()->for($tenantB)->create();
        
        $this->actingAs($userA, 'sanctum');
        
        $response = $this->getJson("/api/v1/clients/{$clientB->id}");
        $response->assertStatus(404);
    }
    
    /** @test */
    public function new_models_automatically_get_current_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        
        $client = Client::factory()->create();
        
        $this->assertEquals($tenant->id, $client->tenant_id);
    }
}
```

### 1.2 Critical Bug Fixes

#### Task 1.2.1: Fix Subscription Model and Service
**Priority:** P0  
**Estimated Time:** 8 hours

**Problem:** `SubscriptionService` references non-existent `Subscription` model.

**Solution:**

1. Create `Subscription` model with full lifecycle support
2. Rewrite `SubscriptionService` with complete business logic
3. Add migration if needed

**File:** `app/Models/Subscription.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Subscription extends Model
{
    use BelongsToTenant;
    
    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_account_id',
        'plan_id',
        'status',
        'billing_cycle',
        'cycle_day',
        'started_at',
        'current_period_start',
        'current_period_end',
        'next_billing_at',
        'cancelled_at',
        'cancel_reason',
        'auto_renew',
        'trial_ends_at',
    ];
    
    protected $casts = [
        'started_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'next_billing_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];
    
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    
    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }
    
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
    
    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
    }
    
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
    
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
    
    public function isTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }
}
```

**File:** `app/Services/Billing/SubscriptionService.php` (complete rewrite)

```php
<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Client;
use App\Models\Invoice;
use App\Jobs\GenerateInvoiceJob;
use Illuminate\Support\Carbon;
use App\Services\Billing\InvoiceService;

class SubscriptionService
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}
    
    public function create(Client $client, Plan $plan, array $options = []): Subscription
    {
        $subscription = Subscription::create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'client_account_id' => $options['client_account_id'] ?? null,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => $options['billing_cycle'] ?? 'monthly',
            'cycle_day' => $options['cycle_day'] ?? now()->day,
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => $this->calculatePeriodEnd(now(), $options['billing_cycle'] ?? 'monthly'),
            'next_billing_at' => $this->calculateNextBilling(now(), $options['billing_cycle'] ?? 'monthly'),
            'auto_renew' => $options['auto_renew'] ?? true,
        ]);
        
        // Generate first invoice
        GenerateInvoiceJob::dispatch($subscription);
        
        return $subscription;
    }
    
    public function cancel(Subscription $subscription, ?string $reason = null): Subscription
    {
        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'auto_renew' => false,
        ]);
        
        return $subscription;
    }
    
    public function renew(Subscription $subscription): Invoice
    {
        if (!$subscription->isActive() || !$subscription->auto_renew) {
            throw new \InvalidException('Subscription cannot be renewed');
        }
        
        // Update period
        $subscription->update([
            'current_period_start' => $subscription->current_period_end,
            'current_period_end' => $this->calculatePeriodEnd(
                $subscription->current_period_end,
                $subscription->billing_cycle
            ),
            'next_billing_at' => $this->calculateNextBilling(
                $subscription->current_period_end,
                $subscription->billing_cycle
            ),
        ]);
        
        // Generate renewal invoice
        return $this->invoiceService->generateForSubscription($subscription);
    }
    
    private function calculatePeriodEnd(Carbon $start, string $cycle): Carbon
    {
        return match ($cycle) {
            'monthly' => $start->copy()->addMonth(),
            'quarterly' => $start->copy()->addMonths(3),
            'annual' => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };
    }
    
    private function calculateNextBilling(Carbon $start, string $cycle): Carbon
    {
        return match ($cycle) {
            'monthly' => $start->copy()->addMonth(),
            'quarterly' => $start->copy()->addMonths(3),
            'annual' => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };
    }
}
```

#### Task 1.2.2: Implement PollRouterTrafficJob
**Priority:** P0  
**Estimated Time:** 4 hours

**File:** `app/Jobs/PollRouterTrafficJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\Network\MikroTikService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\NetworkTraffic;

class PollRouterTrafficJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function handle(MikroTikService $mikrotik): void
    {
        $routers = Router::where('tenant_id', app('currentTenant')->id)
            ->where('is_active', true)
            ->get();
            
        foreach ($routers as $router) {
            try {
                $interfaces = $mikrotik->getInterfaceTraffic($router);
                
                foreach ($interfaces as $iface) {
                    NetworkTraffic::updateOrCreate(
                        [
                            'router_id' => $router->id,
                            'interface' => $iface['name'],
                            'recorded_at' => now()->startOfMinute(),
                        ],
                        [
                            'rx_bytes' => $iface['rx-byte'] ?? 0,
                            'tx_bytes' => $iface['tx-byte'] ?? 0,
                            'rx_packets' => $iface['rx-packet'] ?? 0,
                            'tx_packets' => $iface['tx-packet'] ?? 0,
                            'tenant_id' => $router->tenant_id,
                        ]
                    );
                }
                
                $router->update([
                    'last_seen_at' => now(),
                    'status' => 'online',
                ]);
            } catch (\Exception $e) {
                $router->update(['status' => 'offline']);
                // TODO: Dispatch alert if offline > 5 minutes
            }
        }
    }
}
```

#### Task 1.2.3: Fix StorePaymentRequest Authorization
**Priority:** P0  
**Estimated Time:** 1 hour

**File:** `app/Http/Requests/Payment/StorePaymentRequest.php`

```php
public function authorize(): bool
{
    $user = $this->user();
    $tenant = app('currentTenant');
    
    return $user 
        && $user->can('record-payments')
        && $user->tenant_id === $tenant?->id;
}
```

#### Task 1.2.4: Fix Orphaned Migration
**Priority:** P1  
**Estimated Time:** 30 minutes

**File:** `database/migrations/2026_04_25_134600_add_indexes_for_mpesa_callbacks_and_failures.php`

Remove references to non-existent tables `mpesa_callbacks` and `payment_failures`.

### 1.3 Security Hardening

#### Task 1.3.1: Make M-Pesa HMAC Validation Mandatory
**Priority:** P0  
**Estimated Time:** 2 hours

**File:** `app/Http/Middleware/ValidateMpesaCallback.php`

```php
public function handle(Request $request, Closure $next): Response
{
    $secret = config('services.mpesa.callback_secret');
    
    // HMAC validation is NOT optional
    if (empty($secret)) {
        abort(500, 'M-Pesa callback secret not configured');
    }
    
    $signature = hash_hmac('sha256', $request->getContent(), $secret);
    
    abort_if(
        !hash_equals($signature, $request->header('X-Safaricom-Signature', '')),
        401,
        'Invalid M-Pesa signature'
    );
    
    return $next($request);
}
```

#### Task 1.3.2: Fix M-Pesa Race Condition
**Priority:** P0  
**Estimated Time:** 2 hours

**File:** `app/Services/Mpesa/MpesaService.php`

Add pessimistic locking to STK callback processing:

```php
public function processStkCallback(array $payload): void
{
    $checkoutRequestId = $payload['Body']['stkCallback']['CheckoutRequestID'];
    
    DB::transaction(function () use ($checkoutRequestId, $payload) {
        $transaction = MpesaTransaction::lockForUpdate()
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();
        
        // Idempotency: already processed
        if ($transaction && $transaction->status === 'completed') {
            return;
        }
        
        // Process payment...
    });
}
```

#### Task 1.3.3: Add Rate Limiting to Exports
**Priority:** P1  
**Estimated Time:** 1 hour

**File:** `routes/api.php`

Apply rate limiting to all export endpoints:
```php
Route::get('/{type}/export', [ReportController::class, 'export'])
    ->middleware(['permission:export reports', 'throttle:exports']);
```

Add to `app/Http/Kernel.php`:
```php
RateLimiter::for('exports', function (Request $request) {
    return Limit::perHour(20)->by($request->user()?->tenant_id ?? $request->ip());
});
```

### 1.4 Performance Fixes

#### Task 1.4.1: Fix N+1 Queries
**Priority:** P0  
**Estimated Time:** 3 hours

**Files to fix:**
- `app/Services/Billing/InvoiceService.php` (line ~160)
- `app/Services/Dashboard/DashboardService.php` (line ~157)
- `app/Services/Reporting/ReportService.php` (line ~18)

Example fix in `DashboardService`:
```php
// BEFORE
$topDownloaders = ClientAccount::with('client')->get()->take(10);

// AFTER
$topDownloaders = ClientAccount::with('client')
    ->where('tenant_id', app('currentTenant')->id)
    ->orderByDesc('download_bytes')
    ->limit(10)
    ->get();
```

#### Task 1.4.2: Add Missing Database Indexes
**Priority:** P0  
**Estimated Time:** 2 hours

**File:** `database/migrations/2026_08_06_000002_add_missing_indexes.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'due_date'], 'idx_invoices_tenant_status_due');
            $table->index(['tenant_id', 'client_id', 'status'], 'idx_invoices_tenant_client_status');
        });
        
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'client_id', 'created_at'], 'idx_payments_tenant_client_date');
            $table->index(['tenant_id', 'invoice_id', 'status'], 'idx_payments_tenant_invoice');
        });
        
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'expiry_date'], 'idx_accounts_tenant_expiry');
            $table->index(['tenant_id', 'client_id', 'status'], 'idx_accounts_tenant_client');
        });
        
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'client_id', 'created_at'], 'idx_sms_tenant_client_date');
            $table->index(['tenant_id', 'status', 'created_at'], 'idx_sms_tenant_status');
        });
    }
    
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_tenant_status_due');
            $table->dropIndex('idx_invoices_tenant_client_status');
        });
        
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_tenant_client_date');
            $table->dropIndex('idx_payments_tenant_invoice');
        });
        
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_accounts_tenant_expiry');
            $table->dropIndex('idx_accounts_tenant_client');
        });
        
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex('idx_sms_tenant_client_date');
            $table->dropIndex('idx_sms_tenant_status');
        });
    }
};
```

#### Task 1.4.3: Implement Dashboard Caching
**Priority:** P0  
**Estimated Time:** 3 hours

**File:** `app/Http/Controllers/Api/DashboardController.php`

```php
public function stats(Request $request)
{
    $tenantId = app('currentTenant')->id;
    $userId = $request->user()->id;
    
    return Cache::tags(["tenant:{$tenantId}", 'dashboard'])
        ->remember("dashboard:stats:{$tenantId}:{$userId}", 600, function () {
            return $this->dashboardService->getStats();
        });
}

public function traffic(Request $request)
{
    $tenantId = app('currentTenant')->id;
    
    return Cache::tags(["tenant:{$tenantId}", 'dashboard'])
        ->remember("dashboard:traffic:{$tenantId}", 300, function () {
            return $this->dashboardService->getTraffic();
        });
}
```

### 1.5 API Standardization

#### Task 1.5.1: Create ApiResponse Trait
**Priority:** P0  
**Estimated Time:** 2 hours

**File:** `app/Http/Traits/ApiResponse.php`

```php
<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success($data = null, string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => null,
        ], $status);
    }
    
    protected function error(string $message, int $status = 400, array $errors = null, string $code = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
        ], $status);
    }
    
    protected function paginated($data, int $total, int $perPage = 25, int $currentPage = 1): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => null,
            'meta' => [
                'page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }
}
```

**Phase 1 Deliverables:**
- ✅ All P0 security fixes deployed
- ✅ Multi-tenant isolation fully functional
- ✅ Test suite passes with tenant isolation tests
- ✅ Performance benchmarks met (< 200ms p95 API response)
- ✅ All critical bugs fixed

---

## Phase 2 — Core SaaS Features (Weeks 5-10) 🟠 HIGH

**Goal:** Complete subscription management, dunning, wallet, and tenant onboarding.

### 2.1 Subscription Management Enhancement

#### Task 2.1.1: Subscription Change Tracking
**Priority:** P1  
**Estimated Time:** 4 hours

Create `SubscriptionChange` model to track upgrades/downgrades/proration.

#### Task 2.1.2: Proration Engine
**Priority:** P1  
**Estimated Time:** 6 hours

Implement proration calculations for mid-cycle plan changes.

#### Task 2.1.3: Auto-Renewal Job
**Priority:** P1  
**Estimated Time:** 3 hours

Create scheduled job to process subscription renewals daily.

### 2.2 Dunning Management

#### Task 2.2.1: Dunning Models
**Priority:** P1  
**Estimated Time:** 4 hours

Create:
- `DunningPolicy` model
- `DunningNotice` model
- Migration files

#### Task 2.2.2: Dunning Engine
**Priority:** P1  
**Estimated Time:** 8 hours

Create `RunDunningEngineJob` that:
- Runs every 6 hours
- Finds overdue invoices
- Applies policy steps
- Sends notifications
- Restricts speeds/suspends accounts

### 2.3 Wallet & Credit System

#### Task 2.3.1: Wallet Models
**Priority:** P1  
**Estimated Time:** 3 hours

Create:
- `Wallet` model
- `WalletTransaction` model

#### Task 2.3.2: Wallet Service
**Priority:** P1  
**Estimated Time:** 6 hours

Implement:
- Credit/debit operations
- Auto-apply to invoices
- Transaction history

### 2.4 Invoice Enhancements

#### Task 2.4.1: Invoice Line Items
**Priority:** P1  
**Estimated Time:** 4 hours

Add support for:
- Proration line items
- One-off charges
- Addon services

#### Task 2.4.2: Tax Management
**Priority:** P1  
**Estimated Time:** 6 hours

Implement:
- Tax rates per tenant
- VAT 16% default for Kenya
- Compound tax support

### 2.5 Tenant Onboarding

#### Task 2.5.1: Setup Wizard
**Priority:** P1  
**Estimated Time:** 8 hours

Create wizard for:
- Company details
- Branding (logo, colors)
- Email/SMS configuration
- Payment gateway setup

**Phase 2 Deliverables:**
- ✅ Subscription lifecycle fully functional
- ✅ Dunning workflow operational
- ✅ Wallet system deployed
- ✅ New tenant onboarding automated
- ✅ Tax management implemented

---

## Phase 3 — Network & Provisioning (Weeks 11-16) 🟡 MEDIUM

**Goal:** Complete IPAM, FUP, RADIUS accounting, hotspot/vouchers.

### 3.1 IPAM (IP Address Management)
**Priority:** P2  
**Estimated Time:** 16 hours

- IP Pool model and migration
- IP Allocation model with history
- Auto-allocation service
- Utilization reports

### 3.2 FUP Engine
**Priority:** P2  
**Estimated Time:** 20 hours

- FUP Tier model
- Usage Cycle model
- Sync job (every 15 min)
- CoA packet sending
- Auto-restore at cycle end

### 3.3 Network Abstraction Layer
**Priority:** P2  
**Estimated Time:** 12 hours

- `NetworkProvisionerInterface`
- `MikroTikProvisioner`
- `RadiusProvisioner`

### 3.4 RADIUS Accounting
**Priority:** P2  
**Estimated Time:** 8 hours

- Enhanced RadiusSession model
- Accounting webhook endpoint
- Usage tracking integration

### 3.5 Hotspot & Voucher System
**Priority:** P2  
**Estimated Time:** 16 hours

- Hotspot Zone model
- Voucher Batch generation
- MikroTik hotspot integration

**Phase 3 Deliverables:**
- ✅ IPAM fully operational
- ✅ FUP engine enforcing bandwidth tiers
- ✅ RADIUS accounting integrated
- ✅ Hotspot/voucher system live

---

## Phase 4 — Ecosystem & Growth (Weeks 17-24) 🟢 LOW

**Goal:** Agent commissions, webhooks, advanced reporting, KYC.

### 4.1 Agent & Commission Management
**Priority:** P3  
**Estimated Time:** 16 hours

- Agent model
- Commission tracking
- Payout management

### 4.2 Webhook Engine
**Priority:** P3  
**Estimated Time:** 12 hours

- Webhook endpoints
- Event subscriptions
- Delivery with retry

### 4.3 SMS Campaign Manager
**Priority:** P3  
**Estimated Time:** 10 hours

- Campaign model
- Segmentation
- Scheduled sending

### 4.4 Advanced Reporting
**Priority:** P3  
**Estimated Time:** 12 hours

- AR aging report
- FUP usage report
- Revenue forecast

### 4.5 KYC Module
**Priority:** P3  
**Estimated Time:** 10 hours

- Document uploads
- Verification workflow
- Expiry tracking

**Phase 4 Deliverables:**
- ✅ Agent/reseller system operational
- ✅ Webhook notifications live
- ✅ Advanced reports available
- ✅ KYC workflow functional

---

## Implementation Guidelines

### Code Standards
- All tenant-scoped models use `BelongsToTenant` trait
- All controllers use `ApiResponse` trait
- All business logic in service classes
- All jobs are idempotent
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
