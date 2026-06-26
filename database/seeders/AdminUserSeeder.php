<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // NEVER use env() in seeders or anywhere outside config files.
        // env() bypasses the config cache and returns null when the cache
        // is active (which it always is during migrate:fresh --seed in production).
        // The correct pattern is to read from a config key, or use env() only
        // inside config/*.php files where it is guaranteed to run before caching.
        $adminPassword = config('app.seed_admin_password', 'Admin@123');
        $staffPassword = config('app.seed_staff_password', 'Staff@123');

        $admin = User::updateOrCreate(
            ['email' => 'admin@primebill.co.ke'],
            [
                'name'     => 'Super Admin',
                'password' => Hash::make($adminPassword),
            ]
        );
        $admin->assignRole('super_admin');

        $staff = User::updateOrCreate(
            ['email' => 'staff@primebill.co.ke'],
            [
                'name'     => 'Staff User',
                'password' => Hash::make($staffPassword),
            ]
        );
        $staff->assignRole('staff');
    }
}