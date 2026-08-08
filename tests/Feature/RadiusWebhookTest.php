<?php

namespace Tests\Feature;

use App\Jobs\ProcessRadiusAccountingJob;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RadiusWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($tenant);
    }

    public function test_webhook_rejects_missing_secret(): void
    {
        $response = $this->postJson('/api/webhooks/radius/accounting', ['foo' => 'bar']);

        $response->assertStatus(401)
                 ->assertJsonPath('success', false);
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $response = $this->postJson('/api/webhooks/radius/accounting', ['foo' => 'bar'], [
            'X-RADIUS-SECRET' => 'wrong-secret',
        ]);

        $response->assertStatus(401)
                 ->assertJsonPath('success', false);
    }

    public function test_webhook_accepts_correct_secret_and_dispatches_job(): void
    {
        Queue::fake();
        config()->set('network.radius_webhook_secret', 'test-secret');

        $response = $this->postJson('/api/webhooks/radius/accounting', [
            'username' => 'testuser',
            'acctstatus' => 'Start',
            'acctinputoctets' => 100,
            'acctoutputoctets' => 200,
        ], [
            'X-RADIUS-SECRET' => 'test-secret',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        Queue::assertPushed(ProcessRadiusAccountingJob::class, function ($job) {
            return $job->payload['username'] === 'testuser';
        });
    }
}
