<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = env('SEED_ADMIN_PASSWORD') ?: 'ChangeMe@Admin123';
        $staffPassword = env('SEED_STAFF_PASSWORD') ?: 'ChangeMe@Staff123';

        $admin = User::updateOrCreate(['email' => 'admin@primebill.co.ke'], [
            'name'     => 'Super Admin',
            'password' => Hash::make($adminPassword),
        ]);
        $admin->assignRole('super_admin');

        $staff = User::updateOrCreate(['email' => 'staff@primebill.co.ke'], [
            'name'     => 'Staff User',
            'password' => Hash::make($staffPassword),
        ]);
        $staff->assignRole('staff');
    }
}
