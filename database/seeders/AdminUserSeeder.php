<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * AdminUserSeeder is intentionally a no-op.
 *
 * The previous implementation created two global (no-tenant) users —
 * admin@primebill.co.ke (super_admin) and staff@primebill.co.ke (staff) —
 * which violated the multi-tenant architecture by creating platform-level
 * users with NULL tenant_id.
 *
 * Admin and staff users are now created per-tenant by TenantUserSeeder,
 * which binds each user to a specific Tenant via TenantUserSeeder.
 *
 * This file is kept for backward compatibility but performs no work.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        Log::info('AdminUserSeeder: no-op — tenant users are created by TenantUserSeeder.');
    }
}