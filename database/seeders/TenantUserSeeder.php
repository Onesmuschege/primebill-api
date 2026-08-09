<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds tenant-specific staff users for each demo tenant.
 *
 * Each tenant gets 5 users with the roles that already exist in the system
 * (super_admin, admin, staff, staff, client-support uses staff). The emails
 * are predictable development credentials so each tenant can be logged into
 * independently for tenant-isolation testing.
 *
 * The Platform Admin is NEVER created here (see TenantSeeder docblock).
 */
class TenantUserSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $password = $this->demoPassword();

        $this->forEachTenant(function (Tenant $tenant) use ($password) {
            $slug = $tenant->slug;

            $users = [
                [
                    'name'  => $tenant->name . ' Administrator',
                    'email' => $slug . '.admin@primebill.test',
                    'role'  => 'super_admin',
                ],
                [
                    'name'  => $tenant->name . ' Staff',
                    'email' => $slug . '.staff@primebill.test',
                    'role'  => 'staff',
                ],
                [
                    'name'  => $tenant->name . ' Support',
                    'email' => $slug . '.support@primebill.test',
                    'role'  => 'staff',
                ],
                [
                    'name'  => $tenant->name . ' Technician',
                    'email' => $slug . '.technician@primebill.test',
                    'role'  => 'staff',
                ],
                [
                    'name'  => $tenant->name . ' Finance',
                    'email' => $slug . '.finance@primebill.test',
                    'role'  => 'admin',
                ],
            ];

            foreach ($users as $user) {
                $model = User::updateOrCreate(
                    ['email' => $user['email']],
                    [
                        'name'              => $user['name'],
                        'password'          => Hash::make($password),
                        'tenant_id'         => $tenant->id,
                        'is_platform_admin' => false,
                        'email_verified_at' => now(),
                    ]
                );

                $model->assignRole($user['role']);
            }
        });

        $this->command->info('TenantUserSeeder: 5 users created per tenant (15 total).');
    }
}
