<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\ClientAccount;
use App\Models\Ticket;
use App\Models\Router;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up tenant for this test
        $this->tenant = Tenant::factory()->create();

        // Create a user for this tenant
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_dashboard_returns_stats(): void
    {
        // Create test data
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create account
        ClientAccount::factory()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // Create payment
        Payment::factory()->create([
            'client_id' => $client->id,
            'status' => 'completed',
        ]);

        // Create invoice
        Invoice::factory()->create([
            'client_id' => $client->id,
            'status' => 'paid',
        ]);

        // Create ticket
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => 'open',
        ]);

        // Create router
        Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'online',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'income_today',
                    'income_month',
                    'active_users',
                    'total_users',
                    'tickets',
                    'account_status',
                    'routers',
                    'plan_distribution',
                ],
            ]);
    }

    public function test_dashboard_returns_analytics(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/analytics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'revenue',
                    'clients',
                    'invoices',
                    'payments',
                ],
            ]);
    }

    public function test_dashboard_returns_invoice_aging(): void
    {
        // Create invoices with different aging
        $client = Client::factory()->create();

        // Current invoice
        Invoice::factory()->create([
            'client_id' => $client->id,
            'status' => 'pending',
            'created_at' => now()->subDays(15),
        ]);

        // Overdue invoice
        Invoice::factory()->create([
            'client_id' => $client->id,
            'status' => 'overdue',
            'created_at' => now()->subDays(45),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/invoice-aging');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'current',
                    'days_30',
                    'days_60',
                    'days_90',
                    'total_outstanding',
                ],
            ]);
    }

    public function test_dashboard_returns_churn_analysis(): void
    {
        // Create some suspended clients
        Client::factory()->count(3)->create(['status' => 'suspended']);
        Client::factory()->count(10)->create(['status' => 'active']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/churn-analysis');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'suspended_this_month',
                    'suspended_last_month',
                    'churn_rate',
                ],
            ]);
    }

    public function test_dashboard_traffic_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/traffic?period=day');

        $response->assertStatus(200);
    }

    public function test_dashboard_top_downloaders_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/top-downloaders');

        $response->assertStatus(200);
    }
}
