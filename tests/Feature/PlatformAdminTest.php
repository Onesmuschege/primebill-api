<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a platform admin user
        $this->platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        // RefreshDatabase keeps one app instance across test methods, so the
        // array cache persists too — stats payloads (platform:stats:v2) leak
        // between tests. Flush so every stats test sees a fresh snapshot.
        Cache::flush();
    }

    public function test_platform_admin_can_view_stats(): void
    {
        // Create test tenants
        Tenant::factory()->count(3)->create(['status' => 'active']);
        Tenant::factory()->count(2)->create(['status' => 'trial']);
        Tenant::factory()->count(1)->create(['status' => 'suspended']);

        // Create test clients
        Client::factory()->count(10)->create();

        // Create test payments
        Payment::factory()->count(5)->create(['status' => 'completed']);

        // Create test invoices
        Invoice::factory()->count(3)->create(['status' => 'pending']);
        Invoice::factory()->count(2)->create(['status' => 'overdue']);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'overview' => [
                        'total_tenants',
                        'active_tenants',
                        'trial_tenants',
                        'suspended_tenants',
                        'total_clients',
                        'total_revenue',
                        'mrr',
                        'arr',
                    ],
                    'tenants' => [
                        'by_status',
                        'by_plan',
                        'new_this_month',
                        'growth_rate',
                    ],
                    'revenue' => [
                        'today',
                        'this_month',
                        'this_year',
                        'daily',
                        'monthly',
                    ],
                    'clients' => [
                        'total',
                        'new_this_month',
                        'by_status',
                    ],
                ],
            ]);
    }

    public function test_platform_admin_can_view_tenants(): void
    {
        Tenant::factory()->count(5)->create();

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/tenants');

        $response->assertStatus(200)
            ->assertJsonCount(6, 'data'); // 5 created + 1 seeded by migration
    }

    public function test_platform_admin_can_filter_tenants_by_status(): void
    {
        Tenant::factory()->count(3)->create(['status' => 'active']);
        Tenant::factory()->count(2)->create(['status' => 'suspended']);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/tenants?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(4, 'data'); // 3 created + 1 seeded active tenant
    }

    public function test_platform_admin_can_search_tenants(): void
    {
        Tenant::factory()->create(['name' => 'Test ISP', 'slug' => 'test-isp']);
        Tenant::factory()->create(['name' => 'Another ISP', 'slug' => 'another-isp']);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/tenants?search=Test');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Test ISP');
    }

    public function test_platform_admin_can_suspend_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/suspend");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'suspended',
        ]);
    }

    public function test_platform_admin_can_activate_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/activate");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_regular_user_cannot_access_platform_routes(): void
    {
        $regularUser = User::factory()->create([
            'is_platform_admin' => false,
        ]);

        $response = $this->actingAs($regularUser)
            ->getJson('/api/platform/stats');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_platform_routes(): void
    {
        $response = $this->getJson('/api/platform/stats');

        $response->assertStatus(401);
    }

    public function test_platform_stats_include_security_metrics(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'security' => [
                        'failed_logins_today',
                        'failed_logins_this_week',
                        'successful_logins_today',
                        'security_events_this_week',
                    ],
                ],
            ]);
    }

    // ─── Tenant CRUD Tests ────────────────────────────────────────────────

    public function test_platform_admin_can_create_tenant(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->postJson('/api/platform/tenants', [
                'name' => 'New ISP',
                'plan' => 'professional',
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'timezone' => 'Africa/Lagos',
                'currency' => 'NGN',
                'admin_name' => 'Admin User',
                'admin_email' => 'admin@newisp.com',
                'admin_password' => 'password123',
                'admin_password_confirmation' => 'password123',
                'contact_email' => 'info@newisp.com',
                'contact_phone' => '+2348012345678',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'New ISP',
                'plan' => 'professional',
            ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'New ISP',
            'plan' => 'professional',
            'status' => 'trial',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@newisp.com',
        ]);
    }

    public function test_platform_admin_can_update_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->putJson("/api/platform/tenants/{$tenant->id}", [
                'name' => 'Updated ISP Name',
                'contact_email' => 'updated@isp.com',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Updated ISP Name',
            'contact_email' => 'updated@isp.com',
        ]);
    }

    public function test_platform_admin_can_delete_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->deleteJson("/api/platform/tenants/{$tenant->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('tenants', [
            'id' => $tenant->id,
        ]);
    }

    // ─── Tenant Configuration Tests ─────────────────────────────────────────

    public function test_platform_admin_can_configure_company(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/company", [
                'contact_email' => 'contact@company.com',
                'contact_phone' => '+254712345678',
                'address' => '123 Main Street, Nairobi',
                'website' => 'https://company.com',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'contact_email' => 'contact@company.com',
            'contact_phone' => '+254712345678',
        ]);
    }

    public function test_platform_admin_can_configure_branding(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/branding", [
                'primary_color' => '#ff0000',
                'secondary_color' => '#00ff00',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'primary_color' => '#ff0000',
            'secondary_color' => '#00ff00',
        ]);
    }

    public function test_platform_admin_can_configure_localization(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/localization", [
                'timezone' => 'Africa/Lagos',
                'currency' => 'NGN',
                'tax_name' => 'VAT',
                'tax_rate' => 7.5,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'timezone' => 'Africa/Lagos',
            'currency' => 'NGN',
            'tax_rate' => 7.5,
        ]);
    }

    // ─── Subscription & Plan Tests ─────────────────────────────────────────

    public function test_platform_admin_can_assign_plan(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'starter']);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/plan", [
                'plan' => 'enterprise',
                'billing_cycle' => 'annual',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'plan' => 'enterprise',
            'billing_cycle' => 'annual',
        ]);
    }

    public function test_platform_admin_can_view_available_plans(): void
    {
        // Seed the plan catalog so the DB-backed endpoint has data.
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/plans');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    [
                        'id',
                        'slug',
                        'name',
                        'price_monthly',
                        'annual_price',
                        'max_clients',
                        'max_users',
                        'max_routers',
                        'features',
                        'is_active',
                    ],
                ],
            ])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'starter')
            ->assertJsonPath('data.1.slug', 'professional')
            ->assertJsonPath('data.2.slug', 'enterprise');
    }

    // ─── Tenant Lifecycle Tests ────────────────────────────────────────────

    public function test_platform_admin_can_suspend_tenant_with_reason(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/suspend", [
                'reason' => 'Non-payment',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'suspended',
            'suspension_reason' => 'Non-payment',
        ]);
    }

    public function test_platform_admin_can_archive_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'archived',
        ]);
    }

    // ─── Quotas & Limits Tests ───────────────────────────────────────────

    public function test_platform_admin_can_update_quotas(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/quotas", [
                'max_clients' => 1000,
                'max_users' => 20,
                'max_routers' => 15,
                'storage_quota_gb' => 100,
                'api_calls_per_month' => 50000,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'max_clients' => 1000,
            'max_users' => 20,
            'max_routers' => 15,
            'storage_quota_gb' => 100,
            'api_calls_per_month' => 50000,
        ]);
    }

    // ─── Feature Flags Tests ───────────────────────────────────────────────

    public function test_platform_admin_can_update_feature_flags(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/features", [
                'feature_flags' => ['custom_reports', 'api_access'],
            ]);

        $response->assertStatus(200);

        $tenant->refresh();
        $this->assertContains('custom_reports', $tenant->feature_flags);
        $this->assertContains('api_access', $tenant->feature_flags);
    }

    public function test_platform_admin_can_add_feature_flag(): void
    {
        $tenant = Tenant::factory()->create(['feature_flags' => []]);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/features/add", [
                'feature' => 'sms',
            ]);

        $response->assertStatus(200);

        $tenant->refresh();
        $this->assertContains('sms', $tenant->feature_flags);
    }

    public function test_platform_admin_can_remove_feature_flag(): void
    {
        $tenant = Tenant::factory()->create(['feature_flags' => ['sms', 'api']]);

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/features/remove", [
                'feature' => 'sms',
            ]);

        $response->assertStatus(200);

        $tenant->refresh();
        $this->assertNotContains('sms', $tenant->feature_flags);
        $this->assertContains('api', $tenant->feature_flags);
    }

    // ─── Health & Billing Tests ──────────────────────────────────────────

    public function test_platform_admin_can_view_tenant_health(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->getJson("/api/platform/tenants/{$tenant->id}/health");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'client_count',
                    'client_limit',
                    'user_count',
                    'router_count',
                    'total_revenue',
                ],
            ]);
    }

    public function test_tenant_health_score_is_explainable_and_bounded(): void
    {
        // Router-less tenant: the network component must be skipped (not
        // penalised) and the weight renormalised across the rest.
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->getJson("/api/platform/tenants/{$tenant->id}/health");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'score' => [
                        'total',
                        'components' => [
                            ['key', 'label', 'weight', 'score', 'factors'],
                        ],
                    ],
                ],
            ]);

        $score = $response->json('data.score');
        $this->assertIsInt($score['total']);
        $this->assertGreaterThanOrEqual(0, $score['total']);
        $this->assertLessThanOrEqual(100, $score['total']);

        $componentKeys = collect($score['components'])->pluck('key')->all();
        $this->assertEqualsCanonicalizing(
            ['billing', 'usage', 'network', 'security', 'activity'],
            $componentKeys
        );

        // Every component must carry at least one human-readable factor —
        // the score must never be unexplained.
        foreach ($score['components'] as $component) {
            $this->assertNotEmpty($component['factors'], "Component {$component['key']} has no factors");
        }

        $network = collect($score['components'])->firstWhere('key', 'network');
        $this->assertTrue($network['skipped']);
        $this->assertNull($network['score']);
    }

    public function test_tenant_health_score_reacts_to_overdue_invoices(): void
    {
        $clean = Tenant::factory()->create();
        $owing = Tenant::factory()->create();

        Invoice::factory()->overdue()->create([
            'tenant_id' => $owing->id,
            'invoice_number' => 'INV-HEALTH-TEST-1',
            'amount' => 1160,
            'total' => 1160,
            'due_date' => now()->subDays(10),
        ]);

        $getScore = fn (Tenant $t) => $this->actingAs($this->platformAdmin)
            ->getJson("/api/platform/tenants/{$t->id}/health")
            ->json('data.score');

        $cleanScore = $getScore($clean);
        $owingScore = $getScore($owing);

        $cleanBilling = collect($cleanScore['components'])->firstWhere('key', 'billing');
        $owingBilling = collect($owingScore['components'])->firstWhere('key', 'billing');

        $this->assertGreaterThan(
            $owingBilling['score'],
            $cleanBilling['score'],
            'Overdue invoices must lower the billing component score'
        );
        $this->assertGreaterThan(
            $owingScore['total'],
            $cleanScore['total'],
            'Overdue invoices must lower the overall health score'
        );
        // The owing tenant's factor list must say WHY.
        $this->assertTrue(
            collect($owingBilling['factors'])->contains(fn ($f) => str_contains($f, 'overdue')),
            'Billing factors must mention the overdue invoice'
        );
    }

    public function test_platform_admin_can_view_tenant_billing(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->getJson("/api/platform/tenants/{$tenant->id}/billing");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'plan',
                    'billing_cycle',
                    'monthly_price',
                    'total_paid',
                ],
            ]);
    }

    public function test_platform_admin_can_view_tenant_subscription(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'trial']);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson("/api/platform/tenants/{$tenant->id}/subscription");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'message',
                    'is_active',
                    'is_trial',
                ],
            ]);
    }

    // ─── Admin User Management Tests ─────────────────────────────────────

    public function test_platform_admin_can_create_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/admin", [
                'name' => 'Tenant Admin',
                'email' => 'admin@tenant.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@tenant.com',
        ]);
    }

    // ─── Impersonation Tests (F3 — reason required, VIEW AS / ACT AS mode) ──

    public function test_impersonation_requires_audit_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('admin');

        $response = $this->withSession([])
            ->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/impersonate", [
                'mode' => 'act',
            ]);

        $response->assertStatus(422);
    }

    public function test_impersonation_with_reason_and_mode_logs_audit_trail(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('admin');

        $response = $this->withSession([])
            ->actingAs($this->platformAdmin)
            ->postJson("/api/platform/tenants/{$tenant->id}/impersonate", [
                'reason' => 'Investigating ISP support ticket #4321',
                'mode' => 'view',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'impersonation_token',
                    'tenant' => ['id', 'name', 'slug'],
                    'admin' => ['id', 'name', 'email'],
                ],
            ]);

        // The impersonation reason and mode must be in the audit trail.
        $this->assertDatabaseHas('system_logs', [
            'action' => 'tenant.impersonated',
            'model' => 'tenant',
            'model_id' => $tenant->id,
            'user_id' => $this->platformAdmin->id,
        ]);
        $this->assertDatabaseHas('system_logs', [
            'action' => 'tenant.impersonated',
            'new_values->mode' => 'view',
        ]);
    }
// ─── Overview / Command Centre queue tests (Phase 3) ────────────────────

    public function test_platform_stats_include_op_queues(): void
    {
        // A tenant on trial expiring within 3 days → must surface.
        $trialTenant = Tenant::factory()->create([
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(3),
        ]);
        // A tenant on trial expiring in 30 days → must NOT surface.
        Tenant::factory()->create([
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(30),
        ]);

        // An overdue PrimeBill invoice → must surface as an account owing.
        $overdueTenant = Tenant::factory()->create();
        PlatformInvoice::create([
            'tenant_id' => $overdueTenant->id,
            'invoice_number' => 'PB-2024-TEST',
            'amount' => 100,
            'tax_amount' => 16,
            'total' => 116,
            'status' => 'overdue',
            'billing_period' => now()->format('Y-m'),
            'issue_date' => now()->subDays(30),
            'due_date' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'ops_queues' => [
                        'expiring_trials' => ['available', 'label', 'count', 'items'],
                        'overdue_accounts' => ['available', 'label', 'count', 'items'],
                        'near_limit' => ['available', 'label', 'count', 'items'],
                        'failed_jobs' => ['available', 'label', 'count', 'items'],
                        'security_events' => ['available', 'label', 'count', 'items'],
                        'failed_integrations' => ['available', 'label', 'count', 'items'],
                        'incidents' => ['available', 'label', 'count', 'items'],
                    ],
                ],
            ])
            ->assertJsonPath('data.ops_queues.expiring_trials.count', 1)
            ->assertJsonPath('data.ops_queues.expiring_trials.items.0.tenant_id', $trialTenant->id);

        // timing-tolerant: Carbon's diffInDays can round down 3 → 2 during
        // the test window, so assert a sane range instead of an exact int.
        $trialItem = $response->json('data.ops_queues.expiring_trials.items.0');
        $this->assertGreaterThanOrEqual(0, $trialItem['days_left']);
        $this->assertLessThanOrEqual(3, $trialItem['days_left']);

        $response->assertJsonPath('data.ops_queues.overdue_accounts.count', 1)
            ->assertJsonPath('data.ops_queues.overdue_accounts.items.0.tenant_id', $overdueTenant->id)
            // Honest backend-gap queues — available:false, not fabricated counts.
            ->assertJsonPath('data.ops_queues.failed_integrations.available', false)
            ->assertJsonPath('data.ops_queues.incidents.available', false);
    }

    public function test_platform_stats_include_mrr_bridge(): void
    {
        $tenant = Tenant::factory()->create();

        // Active subscription started this month → contributes to new MRR.
        TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'price' => 100,
            'starts_at' => now()->startOfMonth()->addDay(),
        ]);

        // Cancelled this month → counts as churned MRR.
        TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'cancelled',
            'billing_cycle' => 'monthly',
            'price' => 40,
            'cancelled_at' => now()->startOfMonth()->addDay(),
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'overview' => [
                        'mrr',
                        'arr',
                        'mrr_new_this_month',
                        'mrr_churned_this_month',
                    ],
                ],
            ])
            ->assertJsonPath('data.overview.mrr_new_this_month', 100)
            ->assertJsonPath('data.overview.mrr_churned_this_month', 40);
    }

    // ─── Phase 5: Revenue Analytics ─────────────────────────────────────────

    public function test_platform_admin_can_view_revenue_analytics(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $tenant = Tenant::factory()->create();
        $plan = SubscriptionPlan::first();

        TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'price' => 99,
            'starts_at' => now()->startOfMonth()->addDay(),
        ]);

        // Create a paid invoice directly (no factory exists for PlatformInvoice).
        PlatformInvoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => null,
            'invoice_number' => 'PB-INV-'.now()->year.'-000001',
            'amount' => 99,
            'tax_amount' => 0,
            'total' => 99,
            'status' => 'paid',
            'billing_period' => now()->format('Y-m'),
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/analytics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'mrr' => ['mrr', 'arr', 'new_this_month', 'churned_this_month', 'active_count', 'trial_count'],
                    'monthly_trend' => [],
                    'by_plan' => [],
                    'by_method',
                    'invoice_status' => ['draft', 'sent', 'paid', 'overdue', 'void'],
                ],
            ])
            ->assertJsonPath('data.mrr.mrr', 99)
            ->assertJsonPath('data.mrr.new_this_month', 99)
            ->assertJsonCount(12, 'data.monthly_trend');
    }

    // ─── Phase 5: Plans CRUD ────────────────────────────────────────────────

    public function test_platform_admin_can_create_plan(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->postJson('/api/platform/plans', [
                'slug' => 'business',
                'name' => 'Business',
                'billing_cycle' => 'monthly',
                'price' => 199,
                'annual_price' => 1990,
                'max_clients' => 5000,
                'max_users' => 25,
                'max_routers' => 20,
                'features' => ['api_access', 'priority_support'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'business')
            ->assertJsonPath('data.name', 'Business');

        $this->assertDatabaseHas('subscription_plans', ['slug' => 'business']);
    }

    public function test_platform_admin_can_update_plan(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        $plan = SubscriptionPlan::where('slug', 'starter')->first();

        $response = $this->actingAs($this->platformAdmin)
            ->putJson("/api/platform/plans/{$plan->id}", [
                'slug' => 'starter',
                'name' => 'Starter Plus',
                'billing_cycle' => 'monthly',
                'price' => 29,
                'annual_price' => 290,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Starter Plus');

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'name' => 'Starter Plus']);
    }

    public function test_platform_admin_cannot_delete_plan_with_subscriptions(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        $plan = SubscriptionPlan::where('slug', 'professional')->first();
        $tenant = Tenant::factory()->create();

        TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->deleteJson("/api/platform/plans/{$plan->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
    }

    public function test_platform_admin_can_delete_unused_plan(): void
    {
        $plan = SubscriptionPlan::create([
            'slug' => 'obsolete',
            'name' => 'Obsolete',
            'billing_cycle' => 'monthly',
            'price' => 50,
            'sort_order' => 99,
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->deleteJson("/api/platform/plans/{$plan->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('subscription_plans', ['id' => $plan->id]);
    }

    public function test_subscription_stats_amortize_annual_subscriptions(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        $plan = SubscriptionPlan::first();
        $tenant = Tenant::factory()->create();

        TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'price' => 99,
        ]);

        TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
            'price' => 1200,
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/subscription-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.mrr', 199)
            ->assertJsonPath('data.arr', 2388);
    }

    // ─── Phase 6: Observability / Security ──────────────────────────────────

    public function test_platform_admin_can_view_security_events(): void
    {
        $tenant = Tenant::factory()->create();

        SystemLog::create([
            'tenant_id' => $tenant->id,
            'action' => 'auth.login.failed',
            'model' => 'User',
            'model_id' => $tenant->id,
            'old_values' => ['reason' => 'invalid_credentials'],
            'ip_address' => '192.0.2.10',
        ]);
        SystemLog::create([
            'tenant_id' => $tenant->id,
            'action' => 'auth.login.success',
            'model' => 'User',
            'old_values' => [],
            'ip_address' => '192.0.2.11',
        ]);
        SystemLog::create([
            'tenant_id' => $tenant->id,
            'action' => 'security.rate_limit_hit',
            'model' => 'Setting',
            'old_values' => ['key' => 'throttle'],
            'ip_address' => '192.0.2.12',
        ]);
        // Non security/auth actions must NOT leak into the security feed.
        SystemLog::create([
            'tenant_id' => $tenant->id,
            'action' => 'tenant.registered',
            'old_values' => [],
            'ip_address' => '192.0.2.13',
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [],
                    'current_page',
                    'last_page',
                    'total',
                ],
            ]);

        $actions = collect($response->json('data.data'))->pluck('action')->all();
        $this->assertContains('auth.login.failed', $actions);
        $this->assertContains('auth.login.success', $actions);
        $this->assertContains('security.rate_limit_hit', $actions);
        $this->assertNotContains('tenant.registered', $actions);
    }

    public function test_security_events_can_be_filtered_by_action(): void
    {
        SystemLog::create(['action' => 'auth.login.failed', 'old_values' => [], 'ip_address' => '192.0.2.10']);
        SystemLog::create(['action' => 'auth.login.success', 'old_values' => [], 'ip_address' => '192.0.2.11']);
        SystemLog::create(['action' => 'security.rate_limit_hit', 'old_values' => [], 'ip_address' => '192.0.2.12']);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/events?action=failed');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('auth.login.failed', $response->json('data.data.0.action'));
    }

    public function test_security_events_can_be_filtered_by_severity(): void
    {
        SystemLog::create(['action' => 'auth.login.failed', 'old_values' => [], 'ip_address' => '192.0.2.10']);
        SystemLog::create(['action' => 'auth.login.success', 'old_values' => [], 'ip_address' => '192.0.2.11']);
        SystemLog::create(['action' => 'security.rate_limit_hit', 'old_values' => [], 'ip_address' => '192.0.2.12']);

        // critical → security.* + auth.login.failed (brute-force escalations)
        $critical = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/events?severity=critical')
            ->json('data.data');

        $actions = collect($critical)->pluck('action')->all();
        $this->assertContains('security.rate_limit_hit', $actions);
        $this->assertContains('auth.login.failed', $actions);
        $this->assertNotContains('auth.login.success', $actions);

        // info → normal auth activity only
        $info = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/events?severity=info')
            ->json('data.data');

        $infoActions = collect($info)->pluck('action')->all();
        $this->assertContains('auth.login.success', $infoActions);
        $this->assertNotContains('security.rate_limit_hit', $infoActions);
    }

    public function test_platform_admin_can_view_suspicious_activity(): void
    {
        // 6 failed attempts from one IP within the 7-day window ⇒ flagged.
        foreach (range(1, 6) as $i) {
            SystemLog::create([
                'action' => 'auth.login.failed',
                'old_values' => ['reason' => "attempt {$i}"],
                'ip_address' => '198.51.100.9',
            ]);
        }

        // A single failure from another IP must NOT be flagged.
        SystemLog::create([
            'action' => 'auth.login.failed',
            'old_values' => ['reason' => 'typo'],
            'ip_address' => '203.0.113.7',
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/suspicious');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'suspicious_ips',
                    'recent_failures',
                    'threshold',
                    'window_days',
                ],
            ]);

        $flagged = collect($response->json('data.suspicious_ips'));
        $this->assertSame(6, $flagged->firstWhere('ip', '198.51.100.9')['attempts']);
        $this->assertNull($flagged->firstWhere('ip', '203.0.113.7'));
    }

    public function test_platform_admin_can_view_security_overview(): void
    {
        SystemLog::create(['action' => 'auth.login.failed', 'old_values' => [], 'ip_address' => '192.0.2.10']);
        SystemLog::create(['action' => 'auth.login.failed', 'old_values' => [], 'ip_address' => '192.0.2.10']);
        SystemLog::create(['action' => 'auth.login.success', 'old_values' => [], 'ip_address' => '192.0.2.11']);
        SystemLog::create(['action' => 'security.rate_limit_hit', 'old_values' => [], 'ip_address' => '192.0.2.12']);

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'failed_logins_today',
                    'failed_logins_this_week',
                    'successful_logins_today',
                    'successful_logins_this_week',
                    'security_events_this_week',
                ],
            ])
            ->assertJsonPath('data.failed_logins_today', 2)
            ->assertJsonPath('data.successful_logins_today', 1)
            ->assertJsonPath('data.security_events_this_week', 1);
    }

    public function test_security_endpoints_require_platform_admin(): void
    {
        $regularUser = User::factory()->create([
            'is_platform_admin' => false,
        ]);

        $this->actingAs($regularUser)
            ->getJson('/api/platform/security/events')
            ->assertStatus(403);

        $this->actingAs($regularUser)
            ->getJson('/api/platform/security/suspicious')
            ->assertStatus(403);

        $this->actingAs($regularUser)
            ->getJson('/api/platform/security/overview')
            ->assertStatus(403);
    }

    public function test_platform_infrastructure_stats_include_service_health(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'infrastructure' => [
                        'status',
                        'avg_response_time',
                        'response_time_unit',
                        'services' => [],
                        'routers' => ['total', 'online', 'offline', 'health_percentage'],
                        'database' => ['driver', 'status'],
                        'cache' => ['driver', 'status'],
                        'queue' => ['default', 'status'],
                    ],
                ],
            ])
            ->assertJsonPath('data.infrastructure.database.status', 'connected')
            ->assertJsonPath('data.infrastructure.cache.status', 'healthy')
            ->assertJsonCount(4, 'data.infrastructure.services');
    }
}
