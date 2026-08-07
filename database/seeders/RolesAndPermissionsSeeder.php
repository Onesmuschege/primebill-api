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
            | Leads (CRM)
            |--------------------------------------------------------------------------
            */
            'view leads',
            'create leads',
            'edit leads',
            'delete leads',

            /*
            |--------------------------------------------------------------------------
            | Prospects (Sales Pipeline)
            |--------------------------------------------------------------------------
            */
            'view prospects',
            'create prospects',
            'edit prospects',
            'delete prospects',

            /*
            |--------------------------------------------------------------------------
            | CRM - Notes
            |--------------------------------------------------------------------------
            */
            'view notes',
            'create notes',
            'edit notes',
            'delete notes',

            /*
            |--------------------------------------------------------------------------
            | CRM - Tags
            |--------------------------------------------------------------------------
            */
            'view tags',
            'create tags',
            'edit tags',
            'delete tags',

            /*
            |--------------------------------------------------------------------------
            | CRM - Custom Fields
            |--------------------------------------------------------------------------
            */
            'view custom-fields',
            'create custom-fields',
            'edit custom-fields',
            'delete custom-fields',

            /*
            |--------------------------------------------------------------------------
            | Field Operations - Work Orders
            |--------------------------------------------------------------------------
            */
            'view work-orders',
            'create work-orders',
            'edit work-orders',
            'delete work-orders',

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
            | Subscriptions
            |--------------------------------------------------------------------------
            */
            'view subscriptions',
            'create subscriptions',
            'edit subscriptions',
            'delete subscriptions',

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

            // Leads (CRM)
            'view leads',
            'create leads',
            'edit leads',
            'delete leads',

            // Prospects (Sales Pipeline)
            'view prospects',
            'create prospects',
            'edit prospects',
            'delete prospects',

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

            // Subscriptions
            'view subscriptions',
            'create subscriptions',
            'edit subscriptions',
            'delete subscriptions',

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

            // Leads (CRM)
            'view leads',
            'create leads',
            'edit leads',

            // Prospects (Sales Pipeline)
            'view prospects',
            'create prospects',
            'edit prospects',

            // Clients
            'view clients',
            'create clients',
            'edit clients',
            'suspend clients',
            'activate clients',

            // Plans
            'view plans',

            // Subscriptions
            'view subscriptions',
            'create subscriptions',
            'edit subscriptions',

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
