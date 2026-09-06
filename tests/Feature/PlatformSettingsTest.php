<?php

namespace Tests\Feature;

use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\SystemLog;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform-level operator settings (PrimeBill itself, not tenant ISPs).
 *
 * Every key in the schema must genuinely drive platform behavior. These tests
 * prove the wiring end-to-end: billing defaults change invoice numbering / due
 * dates, security thresholds change the suspicious-activity flag, and unknown
 * keys are rejected loudly rather than persisted.
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
    }

    public function test_platform_admin_sees_schema_defaults_before_any_persist(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/settings');

        $response->assertOk()
            ->assertJsonPath('data.groups.security', 'Security')
            ->assertJsonPath('data.groups.billing', 'Billing');

        $settings = $response->json('data.settings');

        $this->assertArrayHasKey('security', $settings);
        $this->assertArrayHasKey('billing', $settings);
        $this->assertArrayHasKey('failed_login_threshold', $settings['security']);
        $this->assertArrayHasKey('suspicious_window_days', $settings['security']);
        $this->assertArrayHasKey('invoice_prefix', $settings['billing']);
        $this->assertArrayHasKey('payment_terms_days', $settings['billing']);

        // Persisted values are untouched → schema defaults surface, flagged as defaults.
        $this->assertSame(5, $settings['security']['failed_login_threshold']['value']);
        $this->assertSame(7, $settings['security']['suspicious_window_days']['value']);
        $this->assertSame('PB-INV', $settings['billing']['invoice_prefix']['value']);
        $this->assertSame(14, $settings['billing']['payment_terms_days']['value']);

        foreach ($settings as $group => $keys) {
            foreach ($keys as $meta) {
                $this->assertTrue($meta['is_default']);
            }
        }
    }

    public function test_platform_admin_can_update_settings_and_persist_flags(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->putJson('/api/platform/settings', [
                'settings' => [
                    'failed_login_threshold' => 8,
                    'invoice_prefix'         => 'PB-2026',
                    'payment_terms_days'     => 30,
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updated.0', 'failed_login_threshold')
            ->assertJsonPath('data.settings.billing.invoice_prefix.value', 'PB-2026')
            ->assertJsonPath('data.settings.billing.payment_terms_days.value', 30)
            ->assertJsonPath('data.settings.security.failed_login_threshold.is_default', false);

        $this->assertDatabaseHas('platform_settings', ['key' => 'invoice_prefix', 'value' => 'PB-2026', 'group' => 'billing']);
        $this->assertDatabaseHas('platform_settings', ['key' => 'payment_terms_days', 'value' => '30', 'group' => 'billing']);
        $this->assertDatabaseHas('platform_settings', ['key' => 'failed_login_threshold', 'value' => '8', 'group' => 'security']);

        // Re-reading reflects persisted state, not the in-memory response cache.
        $this->assertSame(8, (new \App\Services\Platform\PlatformSettingsService)->get('failed_login_threshold'));
        $this->assertSame('PB-2026', (new \App\Services\Platform\PlatformSettingsService)->get('invoice_prefix'));
    }

    public function test_unknown_setting_key_is_rejected_loudly(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->putJson('/api/platform/settings', [
                'settings' => ['nonexistent_key' => 'value'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unknown platform settings: nonexistent_key.');

        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_int_settings_are_range_validation(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->putJson('/api/platform/settings', [
                'settings' => ['payment_terms_days' => 0],
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_invoice_prefix_and_payment_terms_drive_platform_billing(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        $plan = SubscriptionPlan::first();
        $tenant = Tenant::factory()->create(['status' => 'active']);

        TenantSubscription::factory()->create([
            'tenant_id'     => $tenant->id,
            'plan_id'       => $plan->id,
            'status'        => 'active',
            'billing_cycle' => 'monthly',
            'price'         => 99,
            'starts_at'     => now()->startOfMonth()->addDay(),
        ]);

        // Operator tunes platform billing defaults…
        $this->actingAs($this->platformAdmin)
            ->putJson('/api/platform/settings', [
                'settings' => [
                    'invoice_prefix'     => 'PB-2026',
                    'payment_terms_days' => 35,
                ],
            ])->assertOk();

        $response = $this->actingAs($this->platformAdmin)
            ->postJson('/api/platform/billing/invoices/generate');

        $response->assertOk()
            ->assertJsonPath('data.invoices', 1);

        $invoice = PlatformInvoice::first();

        $this->assertNotNull($invoice);
        $this->assertStringStartsWith('PB-2026-'.now()->year.'-', $invoice->invoice_number);
        $this->assertTrue($invoice->due_date->isSameDay(now()->addDays(35)));
    }

    public function test_failed_login_threshold_drives_suspicious_activity_flag(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $logFailed = function (string $ip, int $daysAgo = 0) use ($tenant) {
            SystemLog::create([
                'tenant_id'  => $tenant->id,
                'action'     => 'auth.login.failed',
                'model'      => 'User',
                'model_id'   => 1,
                'old_values' => ['reason' => 'invalid_credentials'],
                'ip_address' => $ip,
                'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
            ]);
        };

        // IP A accumulates failures above the operator threshold (3).
        foreach (range(1, 4) as $i) {
            $logFailed('203.0.113.42');
        }
        // IP B stays below the threshold (1 attempt inside the 7-day window).
        $logFailed('203.0.113.43', 6);
        // IP C is below the threshold too (2 attempts).
        $logFailed('203.0.113.44');
        $logFailed('203.0.113.44');

        // With the default threshold of 5, IP A would NOT be flagged…
        $default = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/suspicious');
        $default->assertOk();
        $flaggedDefault = collect($default->json('data.suspicious_ips'))->pluck('ip')->all();
        $this->assertNotContains('203.0.113.42', $flaggedDefault);

        // …but after the operator lowers the threshold, IP A is flagged.
        $this->actingAs($this->platformAdmin)
            ->putJson('/api/platform/settings', [
                'settings' => ['failed_login_threshold' => 3],
            ])->assertOk();

        $response = $this->actingAs($this->platformAdmin)
            ->getJson('/api/platform/security/suspicious');

        $response->assertOk()
            ->assertJsonPath('data.threshold', 3);

        $flagged = collect($response->json('data.suspicious_ips'))->pluck('ip')->all();
        $this->assertContains('203.0.113.42', $flagged);
        $this->assertNotContains('203.0.113.43', $flagged);
        $this->assertNotContains('203.0.113.44', $flagged);
    }

    public function test_non_platform_admin_cannot_read_or_write_settings(): void
    {
        $regularUser = User::factory()->create();

        $this->actingAs($regularUser)
            ->getJson('/api/platform/settings')
            ->assertStatus(403);

        $this->actingAs($regularUser)
            ->putJson('/api/platform/settings', [
                'settings' => ['invoice_prefix' => 'NOPE'],
            ])
            ->assertStatus(403);

        $this->assertSame(0, PlatformSetting::count());
    }
}