<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── Existing seeders (do not reorder — roles must exist before users) ──
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            PlanSeeder::class,

            // ── New test-data seeders (order matters — foreign key dependencies) ──
            // 1. Clients first — invoices and payments reference client_id
            ClientSeeder::class,
            // 2. Invoices second — payments reference invoice_id
            InvoiceSeeder::class,
            // 3. Payments last — depends on both clients and invoices being present
            PaymentSeeder::class,
        ]);
    }
}