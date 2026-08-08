<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherRedeemTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Plan $plan;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'slug'   => 'test-tenant',
        ]);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');

        $this->plan = Plan::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'name'          => 'Hotspot-100MB',
            'type'          => 'hotspot',
            'validity_days' => 30,
            'router_id'     => null,
            'is_active'     => true,
        ]);
    }

    public function test_voucher_redemption_hashes_password_not_plaintext(): void
    {
        Voucher::create([
            'tenant_id'  => $this->tenant->id,
            'code'       => 'TESTCODE',
            'plan_id'    => $this->plan->id,
            'created_by' => $this->user->id,
            'status'     => 'unused',
        ]);

        $response = $this->postJson("/api/portal/{$this->tenant->slug}/captive/redeem", [
            'code'     => 'TESTCODE',
            'username' => 'guestuser',
            'phone'    => '254700000001',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // Find the client account that was created
        $account = ClientAccount::where('username', 'guestuser')->first();

        $this->assertNotNull($account, 'ClientAccount was not created during voucher redemption');

        // The password must be hashed — never stored as plaintext
        $this->assertNotEquals('guestuser', $account->password);
        $this->assertTrue(
            Hash::isHashed($account->password),
            'Password must be hashed, not plaintext'
        );

        // The voucher should be marked as redeemed
        $voucher = Voucher::where('code', 'TESTCODE')->first();
        $this->assertEquals('redeemed', $voucher->status);
        $this->assertNotNull($voucher->redeemed_at);
    }

    public function test_already_redeemed_voucher_rejected(): void
    {
        Voucher::create([
            'tenant_id'    => $this->tenant->id,
            'code'         => 'USEDVCODE',
            'plan_id'      => $this->plan->id,
            'created_by'   => $this->user->id,
            'status'       => 'redeemed',
            'redeemed_at'  => now(),
        ]);

        $response = $this->postJson("/api/portal/{$this->tenant->slug}/captive/redeem", [
            'code'     => 'USEDVCODE',
            'username' => 'guestuser2',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    public function test_unknown_voucher_rejected(): void
    {
        $response = $this->postJson("/api/portal/{$this->tenant->slug}/captive/redeem", [
            'code'     => 'NONEXISTENT',
            'username' => 'guestuser3',
        ]);

        $response->assertStatus(404);
    }
}
