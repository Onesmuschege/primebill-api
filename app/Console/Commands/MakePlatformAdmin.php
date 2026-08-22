<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deliberately CLI-only. is_platform_admin is the single highest-privilege
 * flag in the app — it bypasses tenant scoping entirely (see
 * BelongsToTenant::scopeWithoutTenantScope()) — so nobody should be able to
 * grant it to themselves or anyone else through the UI or an API endpoint.
 * Run it from a machine you already trust:
 *
 *   php artisan platform:make-admin you@example.com
 */
class MakePlatformAdmin extends Command
{
    protected $signature = 'platform:make-admin {email : Email of the user to grant platform-admin access}';
    protected $description = 'Grant platform-admin access to a user (cross-tenant access to every ISP on the PrimeBill ISP Platform)';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No user found with email: {$email}");

            return self::FAILURE;
        }

        if ($user->is_platform_admin) {
            $this->info("{$email} is already a platform admin.");

            return self::SUCCESS;
        }

        if (!$this->confirm("Grant platform-admin access (cross-tenant, every ISP) to {$email}?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $user->update(['is_platform_admin' => true]);

        $this->info("{$email} is now a platform admin.");

        return self::SUCCESS;
    }
}
