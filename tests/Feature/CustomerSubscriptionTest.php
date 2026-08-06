<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Product;
use App\Models\CustomerSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $token;
    protected Tenant $tenant;
    protected Client $client;
    protected Plan $plan;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_can_create_customer_subscription(): void
    {
        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions", [
            'product_id' => $this->product->id,
            'plan_id' => $this->plan->id,
            'name' => 'Test Subscription',
            'type' => 'new',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.name', 'Test Subscription');

        $this->assertDatabaseHas('customer_subscriptions', [
            'client_id' => $this->client->id,
            'product_id' => $this->product->id,
            'plan_id' => $this->plan->id,
            'status' => 'pending',
        ]);
    }

    public function test_can_list_client_subscriptions(): void
    {
        CustomerSubscription::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->getJson("/api/clients/{$this->client->id}/subscriptions", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data.data');
    }

    public function test_can_view_subscription(): void
    {
        $subscription = CustomerSubscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->getJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $subscription->id);
    }

    public function test_can_activate_subscription(): void
    {
        $subscription = CustomerSubscription::factory()->pending()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}/activate", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $subscription->refresh();
        $this->assertEquals('active', $subscription->status);
    }

    public function test_can_suspend_subscription(): void
    {
        $subscription = CustomerSubscription::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}/suspend", [
            'reason' => 'Payment overdue',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'suspended');

        $subscription->refresh();
        $this->assertEquals('suspended', $subscription->status);
    }

    public function test_can_resume_subscription(): void
    {
        $subscription = CustomerSubscription::factory()->suspended()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}/resume", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $subscription->refresh();
        $this->assertEquals('active', $subscription->status);
    }

    public function test_can_cancel_subscription(): void
    {
        $subscription = CustomerSubscription::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}/cancel", [
            'reason' => 'Customer request',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $subscription->refresh();
        $this->assertEquals('cancelled', $subscription->status);
    }

    public function test_can_renew_subscription(): void
    {
        $subscription = CustomerSubscription::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'ends_at' => now()->subDays(5),
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}/renew", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $subscription->refresh();
        $this->assertTrue($subscription->ends_at->isFuture());
    }

    public function test_can_upgrade_subscription(): void
    {
        $oldPlan = Plan::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $newPlan = Plan::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 200]);

        $subscription = CustomerSubscription::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $oldPlan->id,
            'price' => 100,
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions/{$subscription->id}/upgrade", [
            'plan_id' => $newPlan->id,
            'reason' => 'Customer upgrade',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.plan_id', $newPlan->id);

        $subscription->refresh();
        $this->assertEquals($newPlan->id, $subscription->plan_id);
    }

    public function test_can_get_active_subscriptions(): void
    {
        CustomerSubscription::factory()->active()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        CustomerSubscription::factory()->cancelled()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->getJson("/api/clients/{$this->client->id}/subscriptions/active", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_can_get_expiring_soon_subscriptions(): void
    {
        CustomerSubscription::factory()->expiringSoon()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        CustomerSubscription::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'ends_at' => now()->addMonths(3),
        ]);

        $response = $this->getJson("/api/clients/{$this->client->id}/subscriptions/expiring-soon", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_unauthorized_user_cannot_access_subscriptions(): void
    {
        $subscription = CustomerSubscription::factory()->create();

        $response = $this->getJson("/api/clients/{$this->client->id}/subscriptions", [
            'Authorization' => 'Bearer invalid_token',
        ]);

        $response->assertStatus(401);
    }
}
