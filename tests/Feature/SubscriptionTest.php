<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $token;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    public function test_can_list_subscription_plans(): void
    {
        SubscriptionPlan::factory()->count(3)->create();

        $response = $this->getJson('/api/subscription/plans', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_start_trial_subscription(): void
    {
        $plan = SubscriptionPlan::factory()->create(['is_trial_available' => true]);

        $response = $this->postJson('/api/subscription/start-trial', [
            'plan_id' => $plan->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'trial');

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
        ]);
    }

    public function test_can_view_current_subscription(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $subscription = TenantSubscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->getJson('/api/subscription/current', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.subscription.id', $subscription->id)
            ->assertJsonPath('data.subscription.status', $subscription->status)
            ->assertJsonPath('data.plan.slug', $plan->slug);
    }

    public function test_can_convert_trial_to_paid(): void
    {
        $plan = SubscriptionPlan::factory()->create(['is_trial_available' => true]);
        $subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'billing_cycle' => 'monthly',
            'price' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addDays(14),
            'grace_days' => 7,
        ]);

        $response = $this->postJson('/api/subscription/convert', [
            'billing_cycle' => 'monthly',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $subscription->refresh();
        $this->assertEquals('active', $subscription->status);
    }

    public function test_can_cancel_subscription(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $subscription = TenantSubscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/subscription/cancel', [
            'reason' => 'No longer needed',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $subscription->refresh();
        $this->assertEquals('cancelled', $subscription->status);
    }

    public function test_can_view_subscription_usage(): void
    {
        $response = $this->getJson('/api/subscription/usage', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'limits',
                    'usage',
                    'percentages',
                ],
            ]);
    }
}
