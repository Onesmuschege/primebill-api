<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]
            ->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        */

        $permissions = [

            /*
            |--------------------------------------------------------------------------
            | Clients
            |--------------------------------------------------------------------------
            */
            'view clients',
            'create clients',
            'edit clients',
            'delete clients',
            'suspend clients',
            'activate clients',

            /*
            |--------------------------------------------------------------------------
            | Plans
            |--------------------------------------------------------------------------
            */
            'view plans',
            'create plans',
            'edit plans',
            'delete plans',

            /*
            |--------------------------------------------------------------------------
            | FUP
            |--------------------------------------------------------------------------
            */
            'view fup',
            'edit fup',

            /*
            |--------------------------------------------------------------------------
            | Analytics
            |--------------------------------------------------------------------------
            */
            'view analytics',

            /*
            |--------------------------------------------------------------------------
            | Loyalty
            |--------------------------------------------------------------------------
            */
            'view loyalty',
            'manage loyalty',

            /*
            |--------------------------------------------------------------------------
            | Vouchers
            |--------------------------------------------------------------------------
            */
            'view vouchers',
            'create vouchers',
            'edit vouchers',
            'delete vouchers',

            /*
            |--------------------------------------------------------------------------
            | Invoices
            |--------------------------------------------------------------------------
            */
            'view invoices',
            'create invoices',
            'edit invoices',
            'delete invoices',

            /*
            |--------------------------------------------------------------------------
            | Payments
            |--------------------------------------------------------------------------
            */
            'view payments',
            'create payments',
            'delete payments',

            /*
            |--------------------------------------------------------------------------
            | Tickets
            |--------------------------------------------------------------------------
            */
            'view tickets',
            'create tickets',
            'edit tickets',
            'delete tickets',
            'assign tickets',
            'close tickets',

            /*
            |--------------------------------------------------------------------------
            | Routers
            |--------------------------------------------------------------------------
            */
            'view routers',
            'create routers',
            'edit routers',
            'delete routers',

            /*
            |--------------------------------------------------------------------------
            | Radius
            |--------------------------------------------------------------------------
            */
            'view radius',
            'sync radius',

            /*
            |--------------------------------------------------------------------------
            | SMS
            |--------------------------------------------------------------------------
            */
            'view sms',
            'send sms',

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */
            'view reports',
            'export reports',

            /*
            |--------------------------------------------------------------------------
            | Finance
            |--------------------------------------------------------------------------
            */
            'view finance',
            'create expenditure',
            'view commissions',
            'approve commissions',

            /*
            |--------------------------------------------------------------------------
            | Inventory
            |--------------------------------------------------------------------------
            */
            'view inventory',
            'create inventory',
            'edit inventory',
            'delete inventory',

            /*
            |--------------------------------------------------------------------------
            | Administration - Users
            |--------------------------------------------------------------------------
            */
            'view users',
            'create users',
            'edit users',
            'delete users',

            /*
            |--------------------------------------------------------------------------
            | Administration - Roles
            |--------------------------------------------------------------------------
            */
            'view roles',
            'create roles',
            'edit roles',
            'delete roles',

            /*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            */
            'view settings',
            'edit settings',

            /*
            |--------------------------------------------------------------------------
            | Logs
            |--------------------------------------------------------------------------
            */
            'view logs',
        ];

        /*
        |--------------------------------------------------------------------------
        | Create Permissions
        |--------------------------------------------------------------------------
        */

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Super Admin
        |--------------------------------------------------------------------------
        */

        $superAdmin = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        $superAdmin->syncPermissions(Permission::all());

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        $admin = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $admin->syncPermissions([

            // Clients
            'view clients',
            'create clients',
            'edit clients',
            'delete clients',
            'suspend clients',
            'activate clients',

            // Plans
            'view plans',
            'create plans',
            'edit plans',
            'delete plans',

            // FUP
            'view fup',
            'edit fup',

            // Analytics
            'view analytics',

            // Loyalty
            'view loyalty',
            'manage loyalty',

            // Vouchers
            'view vouchers',
            'create vouchers',
            'edit vouchers',
            'delete vouchers',

            // Billing
            'view invoices',
            'create invoices',
            'edit invoices',
            'delete invoices',

            'view payments',
            'create payments',
            'delete payments',

            // Tickets
            'view tickets',
            'create tickets',
            'edit tickets',
            'delete tickets',
            'assign tickets',
            'close tickets',

            // Routers
            'view routers',
            'create routers',
            'edit routers',
            'delete routers',

            // Radius
            'view radius',
            'sync radius',

            // SMS
            'view sms',
            'send sms',

            // Reports
            'view reports',
            'export reports',

            // Finance
            'view finance',
            'create expenditure',
            'view commissions',
            'approve commissions',

            // Inventory
            'view inventory',
            'create inventory',
            'edit inventory',
            'delete inventory',

            // Administration
            'view users',
            'create users',
            'edit users',
            'delete users',

            'view roles',
            'create roles',
            'edit roles',
            'delete roles',

            // Settings
            'view settings',
            'edit settings',

            // Logs
            'view logs',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Staff
        |--------------------------------------------------------------------------
        */

        $staff = Role::firstOrCreate([
            'name' => 'staff',
            'guard_name' => 'web',
        ]);

        $staff->syncPermissions([

            // Clients
            'view clients',
            'create clients',
            'edit clients',
            'suspend clients',
            'activate clients',

            // Plans
            'view plans',

            // FUP
            'view fup',

            // Vouchers
            'view vouchers',

            // Billing
            'view invoices',
            'create invoices',

            'view payments',
            'create payments',

            // Tickets
            'view tickets',
            'create tickets',
            'edit tickets',
            'close tickets',

            // SMS
            'view sms',
            'send sms',

            // Reports
            'view reports',

            // Inventory
            'view inventory',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Client
        |--------------------------------------------------------------------------
        */

        $client = Role::firstOrCreate([
            'name' => 'client',
            'guard_name' => 'web',
        ]);

        $client->syncPermissions([

            'view invoices',

            'view payments',

            'view tickets',
            'create tickets',
        ]);
    }
}