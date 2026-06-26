<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── Core (order matters: roles before users) ──────────────────────
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,

            // ── Network infrastructure ────────────────────────────────────────
            RouterSeeder::class,        // NEW: router needed by plans + traffic

            // ── Plans (depend on router) ──────────────────────────────────────
            PlanSeeder::class,

            // ── Subscribers ───────────────────────────────────────────────────
            ClientSeeder::class,        // clients
            ClientAccountSeeder::class, // NEW: PPPoE accounts per client

            // ── Billing ───────────────────────────────────────────────────────
            InvoiceSeeder::class,
            PaymentSeeder::class,
            LedgerSeeder::class,        // NEW: ledger entries per payment

            // ── Support & comms ───────────────────────────────────────────────
            TicketSeeder::class,        // NEW: tickets + replies
            SmsLogSeeder::class,        // NEW: SMS logs

            // ── Network data ──────────────────────────────────────────────────
            NetworkTrafficSeeder::class, // NEW: 7 days hourly traffic

            // ── Finance & inventory ───────────────────────────────────────────
            ExpenditureSeeder::class,   // NEW: expenses
            InventorySeeder::class,     // NEW: equipment inventory
        ]);
    }
}
