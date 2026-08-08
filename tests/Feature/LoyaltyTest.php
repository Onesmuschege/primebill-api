<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\LoyaltyPoint;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $token;
    protected Tenant $tenant;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->client = Client::factory()->create([
            'tenant_id' => $this->tenant->id,
            'loyalty_points_balance' => 500,
            'referral_code' => 'REF-001',
        ]);
    }

    public function test_can_get_client_loyalty_points(): void
    {
        $response = $this->getJson("/api/loyalty/points/{$this->client->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.balance', 500)
                 ->assertJsonPath('data.referral_code', 'REF-001');
    }

    public function test_can_list_loyalty_transactions(): void
    {
        LoyaltyPoint::create([
            'tenant_id'  => $this->tenant->id,
            'client_id'  => $this->client->id,
            'points'     => 100,
            'type'       => 'earned',
            'reason'     => 'Test award',
        ]);

        $response = $this->getJson('/api/loyalty/transactions', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(1, 'data.data');
    }

    public function test_can_get_loyalty_leaderboard(): void
    {
        $response = $this->getJson('/api/loyalty/leaderboard', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(1, 'data');
    }

    public function test_can_redeem_loyalty_points_against_invoice(): void
    {
        $invoice = Invoice::create([
            'tenant_id'      => $this->tenant->id,
            'client_id'      => $this->client->id,
            'invoice_number' => 'INV-LOY-001',
            'amount'         => 1000,
            'tax'            => 0,
            'total'          => 1000,
            'status'         => 'unpaid',
        ]);

        $response = $this->postJson('/api/loyalty/redeem', [
            'client_id'  => $this->client->id,
            'points'     => 200,
            'invoice_id' => $invoice->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->client->refresh();
        $this->assertEquals(300, $this->client->loyalty_points_balance);

        $invoice->refresh();
        $this->assertEquals(980, $invoice->total);
    }

    public function test_redeem_fails_with_insufficient_points(): void
    {
        $invoice = Invoice::create([
            'tenant_id'      => $this->tenant->id,
            'client_id'      => $this->client->id,
            'invoice_number' => 'INV-LOY-002',
            'amount'         => 1000,
            'tax'            => 0,
            'total'          => 1000,
            'status'         => 'unpaid',
        ]);

        $response = $this->postJson('/api/loyalty/redeem', [
            'client_id'  => $this->client->id,
            'points'     => 600,
            'invoice_id' => $invoice->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);

        $this->client->refresh();
        $this->assertEquals(500, $this->client->loyalty_points_balance);
    }
}
