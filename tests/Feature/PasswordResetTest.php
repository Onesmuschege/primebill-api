<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_success_for_valid_email(): void
    {
        $user = User::factory()->create(['email' => 'admin@test.com']);

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user  = User::factory()->create(['email' => 'reset@test.com']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/password/reset', [
            'email'                 => $user->email,
            'token'                 => $token,
            'password'              => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
