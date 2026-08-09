<?php

namespace Database\Seeders\Traits;

use App\Models\Tenant;
use Closure;

/**
 * Reusable helper for multi-tenant seeders.
 *
 * Every tenant-owned model uses the BelongsToTenant trait, which auto-fills
 * tenant_id on create() from the current tenant. Outside an HTTP request
 * (i.e. during seeding) the current tenant is null, so we must explicitly
 * bind each tenant before creating its records. This trait wraps that
 * pattern so every seeder stays consistent and can never leak a record
 * into the wrong tenant.
 */
trait SeedsForTenant
{
    /**
     * Run a callback with each development tenant bound as "current".
     * The callback receives the Tenant model.
     */
    protected function forEachTenant(Closure $callback): void
    {
        $slugs = ['primenet-isp', 'swiftlink-communications', 'metrowave-internet'];

        foreach ($slugs as $slug) {
            $tenant = Tenant::where('slug', $slug)->first();

            if (! $tenant) {
                $this->command->warn("Tenant '{$slug}' not found — skipping.");
                continue;
            }

            Tenant::setCurrent($tenant);
            $callback($tenant);
            Tenant::setCurrent(null);
        }
    }

    /**
     * Resolve a tenant by slug (throws if missing).
     */
    protected function tenant(string $slug): Tenant
    {
        return Tenant::where('slug', $slug)->firstOrFail();
    }

    /**
     * Development-only password. All tenant demo users share it so the
     * environment is easy to test. NEVER used in production.
     */
    protected function demoPassword(): string
    {
        return config('app.seed_demo_password', 'Demo@1234');
    }
}
