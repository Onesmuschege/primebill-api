<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\MfaRecoveryCode;
use App\Services\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MfaRecoveryCodeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $user;
    private MfaService $mfaService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->mfaService = app(MfaService::class);
    }

    private function enableMfaWithRecoveryCodes(): array
    {
        $this->user->mfa_secret = encrypt('test_secret_key_12345');
        $this->user->save();

        return $this->mfaService->enableMfa($this->user);
    }

    #[Test]
    public function recovery_codes_are_stored_as_hashes_not_plaintext()
    {
        $codes = $this->enableMfaWithRecoveryCodes();

        $this->assertCount(8, $codes);

        // The plaintext codes must NOT be anywhere in the DB.
        $stored = MfaRecoveryCode::where('user_id', $this->user->id)->get();
        $this->assertCount(8, $stored);

        foreach ($stored as $record) {
            $this->assertEquals(64, strlen($record->code_hash)); // SHA256 hex
            $this->assertNotEquals($record->code_hash, $codes[0]);
        }

        // Every stored hash is deterministic but not reversible — verify each
        // plaintext code maps to a stored hash via the same one-way function.
        foreach ($codes as $code) {
            $hash = hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
            $this->assertDatabaseHas('mfa_recovery_codes', [
                'user_id' => $this->user->id,
                'code_hash' => $hash,
            ]);
        }
    }

    #[Test]
    public function recovery_code_is_single_use()
    {
        $codes = $this->enableMfaWithRecoveryCodes();
        $code = $codes[0];

        // First use succeeds and consumes the code.
        $this->assertTrue($this->mfaService->verifyRecoveryCode($this->user, $code));
        $this->assertTrue($this->mfaService->recoveryCodeCount($this->user) === 7);

        // Reuse is rejected.
        $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, $code));
        $this->assertTrue($this->mfaService->recoveryCodeCount($this->user) === 7);
    }

    #[Test]
    public function invalid_recovery_code_is_rejected()
    {
        $this->enableMfaWithRecoveryCodes();

        $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, 'INVALIDCODE1'));
        $this->assertSame(8, $this->mfaService->recoveryCodeCount($this->user));
    }

    #[Test]
    public function recovery_code_lockout_after_too_many_attempts()
    {
        $codes = $this->enableMfaWithRecoveryCodes();
        $code = $codes[0];

        // Exhaust the code's attempt budget with wrong codes.
        for ($i = 0; $i < MfaRecoveryCode::MAX_ATTEMPTS; $i++) {
            $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, 'WRONGCODE' . $i));
        }

        // The real code is now locked out.
        $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, $code));
        $this->assertSame(8, $this->mfaService->recoveryCodeCount($this->user));
    }

    #[Test]
    public function regeneration_invalidates_old_codes()
    {
        $oldCodes = $this->enableMfaWithRecoveryCodes();

        $newCodes = $this->mfaService->regenerateRecoveryCodes($this->user);

        // Old codes no longer work.
        $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, $oldCodes[0]));

        // New codes work.
        $this->assertTrue($this->mfaService->verifyRecoveryCode($this->user, $newCodes[0]));

        // Only the new set exists.
        $this->assertSame(7, $this->mfaService->recoveryCodeCount($this->user));
    }

    #[Test]
    public function recovery_codes_are_never_returned_in_status_response()
    {
        $this->enableMfaWithRecoveryCodes();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/mfa/status');

        $response->assertStatus(200)
            ->assertJsonStructure(['enabled', 'enabled_at'])
            ->assertJsonMissing(['recovery_codes'])
            ->assertJsonMissing(['backup_codes']);
    }

    #[Test]
    public function recovery_codes_are_never_exposed_in_user_profile()
    {
        $this->enableMfaWithRecoveryCodes();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200);
        $response->assertJsonMissing(['mfa_backup_codes']);
        $response->assertJsonMissing(['mfa_secret']);
        $response->assertJsonMissing(['code_hash']);
    }

    #[Test]
    public function customer_tenant_cannot_use_other_tenant_recovery_codes()
    {
        $codes = $this->enableMfaWithRecoveryCodes();

        // Create a user in another tenant and attempt to use the first user's code.
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->otherTenant->id,
            'mfa_secret' => encrypt('other_secret_key_12345'),
        ]);

        $this->assertFalse($this->mfaService->verifyRecoveryCode($otherUser, $codes[0]));

        // Original user's code is still intact.
        $this->assertTrue($this->mfaService->verifyRecoveryCode($this->user, $codes[0]));
    }

    #[Test]
    public function unauthorized_user_cannot_access_recovery_code_endpoints()
    {
        $response = $this->postJson('/api/mfa/backup-codes', ['code' => '123456']);
        $response->assertStatus(401);
    }

    #[Test]
    public function disabling_mfa_invalidates_all_recovery_codes()
    {
        $this->enableMfaWithRecoveryCodes();

        $this->mfaService->disableMfa($this->user);

        $this->assertSame(0, MfaRecoveryCode::where('user_id', $this->user->id)->count());
        $this->assertFalse($this->mfaService->isEnabled($this->user));
    }

    #[Test]
    public function expired_recovery_codes_are_rejected()
    {
        $codes = $this->enableMfaWithRecoveryCodes();

        // Simulate expiration.
        MfaRecoveryCode::where('user_id', $this->user->id)->update([
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, $codes[0]));
    }

    #[Test]
    public function challenge_with_recovery_code_returns_session_token()
    {
        $codes = $this->enableMfaWithRecoveryCodes();

        // Login to get the mfa-pending token.
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $mfaToken = $loginResponse->json('data.mfa_token');
        $this->assertNotNull($mfaToken);

        // Use the recovery code to complete MFA.
        $response = $this->withHeader('Authorization', 'Bearer ' . $mfaToken)
            ->postJson('/api/mfa/challenge', [
                'code' => $codes[0],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions']]);

        // The code was consumed only once.
        $this->assertFalse($this->mfaService->verifyRecoveryCode($this->user, $codes[0]));
    }

    #[Test]
    public function challenge_with_reused_recovery_code_is_rejected()
    {
        $codes = $this->enableMfaWithRecoveryCodes();

        // Consume the code once via the challenge flow.
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        $mfaToken = $loginResponse->json('data.mfa_token');
        $this->withHeader('Authorization', 'Bearer ' . $mfaToken)
            ->postJson('/api/mfa/challenge', ['code' => $codes[0]])
            ->assertStatus(200);

        // Re-login and try the same code again.
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        $mfaToken = $loginResponse->json('data.mfa_token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $mfaToken)
            ->postJson('/api/mfa/challenge', ['code' => $codes[0]]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid verification code']);
    }

    #[Test]
    public function audit_log_does_not_contain_recovery_codes()
    {
        $codes = $this->enableMfaWithRecoveryCodes();

        // Exercise the real HTTP challenge flow so SystemLog audit entries are
        // created by MfaController (service-level calls never write a log).
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $mfaToken = $loginResponse->json('data.mfa_token');
        $this->assertNotNull($mfaToken, 'Expected an mfa_token from login.');

        $this->withHeader('Authorization', 'Bearer ' . $mfaToken)
            ->postJson('/api/mfa/challenge', ['code' => $codes[0]])
            ->assertStatus(200);

        // We expect at least the login.mfa_challenge + mfa_challenge_passed logs.
        $logs = \App\Models\SystemLog::where('user_id', $this->user->id)->get();
        $this->assertTrue($logs->count() > 0, 'Expected MFA audit logs to exist.');

        // No SystemLog entry may contain the plaintext code (nor should the
        // plaintext code appear anywhere in logged payloads).
        foreach ($logs as $log) {
            $this->assertStringNotContainsString($codes[0], (string) $log->action);
            $this->assertStringNotContainsString($codes[0], (string) $log->old_values);
            $this->assertStringNotContainsString($codes[0], (string) $log->new_values);
            $this->assertStringNotContainsString($codes[0], (string) $log->ip_address);
        }
    }
}
