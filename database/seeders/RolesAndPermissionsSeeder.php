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
            | Payment Allocations
            |--------------------------------------------------------------------------
            */
            'view payment-allocations',
            'create payment-allocations',

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
            | Inventory Engine workflows (Phase D2)
            |--------------------------------------------------------------------------
            */
            'inventory.stock.view',
            'inventory.stock.receive',
            'inventory.stock.issue',
            'inventory.stock.adjust',
            'inventory.transfer.view',
            'inventory.transfer.create',
            'inventory.transfer.approve',
            'inventory.transfer.dispatch',
            'inventory.transfer.receive',
            'inventory.transfer.cancel',
            'inventory.transfer.reverse',
            'inventory.po.view',
            'inventory.po.create',
            'inventory.po.submit',
            'inventory.po.approve',
            'inventory.po.receive',
            'inventory.po.complete',
            'inventory.po.cancel',

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

/*
            |--------------------------------------------------------------------------
            | IPAM
            |--------------------------------------------------------------------------
            */
            'view ipam',
            'manage ipam',

/*
            |--------------------------------------------------------------------------
            | Network Operations Center (NOC)
            |--------------------------------------------------------------------------
            */
            'view network',
            'manage network',

            /*
            |--------------------------------------------------------------------------
            | Incidents
            |--------------------------------------------------------------------------
            */
            'view incidents',
            'create incidents',
            'edit incidents',
            'delete incidents',

            /*
            |--------------------------------------------------------------------------
            | Fiber / OLT
            |--------------------------------------------------------------------------
            */
            'view fiber',
            'manage fiber',

            /*
            |--------------------------------------------------------------------------
            | Reconciliation catalog domains (Phase D)
            |--------------------------------------------------------------------------
            */
            'view service-catalog',
            'manage service-catalog',
            'view equipment',
            'manage equipment',
            'view router-config',
            'manage router-config',
            'view radius-advanced',
            'manage radius-advanced',
            'view fiber-ext',
            'manage fiber-ext',
            'view inventory-ext',
            'manage inventory-ext',
            'view support-catalog',
            'manage support-catalog',
            'view communications',
            'manage communications',
            'view customer-experience',
            'manage customer-experience',
            'view security-admin',
            'manage security-admin',
            'view field-ops',
            'manage field-ops',
                        'view reporting',
            'manage reporting',

                        // RMA (Returns / Replacements / Repairs) — Phase 4 (Field Operations)
            'view rmas',
            'create rmas',
            'edit rmas',
            'delete rmas',

            // Collections / Dunning
            'view collections',
            'manage dunning',
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

                        // Collections / Dunning (Billing)
            'view collections',
            'manage dunning',

            'view payments',
            'create payments',
            'delete payments',

            // Payment Allocations
            'view payment-allocations',
            'create payment-allocations',

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

            // Inventory Engine workflows (Phase D2)
            'inventory.stock.view',
            'inventory.stock.receive',
            'inventory.stock.issue',
            'inventory.stock.adjust',
            'inventory.transfer.view',
            'inventory.transfer.create',
            'inventory.transfer.approve',
            'inventory.transfer.dispatch',
            'inventory.transfer.receive',
            'inventory.transfer.cancel',
            'inventory.transfer.reverse',
            'inventory.po.view',
            'inventory.po.create',
            'inventory.po.submit',
            'inventory.po.approve',
            'inventory.po.receive',
            'inventory.po.complete',
            'inventory.po.cancel',

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

            // IPAM
            'view ipam',
            'manage ipam',

// NOC
            'view network',
            'manage network',
            'view incidents',
            'create incidents',
            'edit incidents',
            'delete incidents',

            // Fiber / OLT
            'view fiber',
            'manage fiber',

            // Reconciliation catalog domains (Phase D)
            'view service-catalog',
            'manage service-catalog',
            'view equipment',
            'manage equipment',
            'view router-config',
            'manage router-config',
            'view radius-advanced',
            'manage radius-advanced',
            'view fiber-ext',
            'manage fiber-ext',
            'view inventory-ext',
            'manage inventory-ext',
            'view support-catalog',
            'manage support-catalog',
            'view communications',
            'manage communications',
            'view customer-experience',
            'manage customer-experience',
            'view security-admin',
            'manage security-admin',
            'view field-ops',
            'manage field-ops',
                        'view reporting',
            'manage reporting',

            // RMA (Returns / Replacements / Repairs) — Phase 4 (Field Operations)
            'view rmas',
            'create rmas',
            'edit rmas',
            'delete rmas',
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

            // Collections / Dunning — staff view-only
            'view collections',

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

                        // NOC (view-only for staff)
            'view network',
            'view incidents',
            'view rmas',
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
