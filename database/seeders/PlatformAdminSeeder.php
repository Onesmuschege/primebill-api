<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the dedicated development Platform Administrator.
 *
 *     Name:     Platform Administrator
 *     Email:    platform@primebill.test
 *     Password: c (via SEED_DEMO_PASSWORD, same as every demo user)
 *
 * This exists because `platform:make-admin` only promotes an EXISTING user —
 * after `migrate:fresh --seed` there was nobody to promote, leaving zero
 * platform admins and no way into the Platform Console without hand-running
 * tinker first.
 *
 * Deliberate constraints (do not loosen):
 *   - Mirrors MakePlatformAdmin semantics EXACTLY: sets only
 *     `is_platform_admin`, assigns NO Spatie role. Roles are tenant-scoped;
 *     platform access is gated solely by the flag + EnsurePlatformAdmin.
 *   - `tenant_id` stays NULL — platform admins are cross-tenant by definition
 *     and TenantUserSeeder explicitly sets is_platform_admin=false for its own
 *     15 users, so the two seeders can never collide or overwrite each other.
 *   - SKIPS ENTIRELY in production: a known-password superuser must never be
 *     plantable there. In production use `php artisan platform:make-admin`.
 */
class PlatformAdminSeeder extends Seeder
{
    public const EMAIL = 'platform@primebill.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn(sprintf(
                'PlatformAdminSeeder: skipped — refusing to create a known-password platform admin in production. Use "php artisan platform:make-admin <email>" instead.'
            ));

            return;
        }

        $password = config('app.seed_demo_password', 'Demo@1234');

        // updateOrCreate forces ALL values on an existing row too, so re-running
        // the seeder always restores the correct name/password/flag — e.g. if a
        // previous experiment left the account half-configured.
        $admin = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Platform Administrator',
                'password' => Hash::make($password),
                'tenant_id' => null,
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info(sprintf(
            'PlatformAdminSeeder: %s ready (is_platform_admin=true, development only).',
            $admin->email
        ));
    }
}
