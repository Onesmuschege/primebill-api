<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\PlatformInvoice;
use App\Models\TenantSubscription;
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
        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/plans');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'starter' => ['name', 'monthly_price', 'max_clients'],
                    'professional' => ['name', 'monthly_price', 'max_clients'],
                    'enterprise' => ['name', 'monthly_price', 'max_clients'],
                ],
            ]);
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
}
