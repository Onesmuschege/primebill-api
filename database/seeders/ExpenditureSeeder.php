<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Seeds realistic ISP operating expenditures for the last 3 months.
 * Categories: Bandwidth, Fuel, Salaries, Equipment, Maintenance, Office.
 */
class ExpenditureSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::where('email', 'admin@primebill.co.ke')->value('id');

        $expenditures = [
            // ── Bandwidth costs ───────────────────────────────────────────────
            ['category' => 'Bandwidth',    'description' => 'Upstream bandwidth — Safaricom Business (June 2026)',    'amount' => 45000.00, 'date' => Carbon::now()->startOfMonth()],
            ['category' => 'Bandwidth',    'description' => 'Upstream bandwidth — Safaricom Business (May 2026)',     'amount' => 45000.00, 'date' => Carbon::now()->subMonth()->startOfMonth()],
            ['category' => 'Bandwidth',    'description' => 'Upstream bandwidth — Safaricom Business (April 2026)',   'amount' => 42000.00, 'date' => Carbon::now()->subMonths(2)->startOfMonth()],

            // ── Fuel / generator ──────────────────────────────────────────────
            ['category' => 'Fuel',         'description' => 'Generator fuel — server room (June)',   'amount' => 8500.00,  'date' => Carbon::now()->subDays(5)],
            ['category' => 'Fuel',         'description' => 'Generator fuel — server room (May)',    'amount' => 7800.00,  'date' => Carbon::now()->subMonth()->subDays(3)],
            ['category' => 'Fuel',         'description' => 'Field technician vehicle fuel (June)',  'amount' => 4200.00,  'date' => Carbon::now()->subDays(10)],

            // ── Salaries ──────────────────────────────────────────────────────
            ['category' => 'Salaries',     'description' => 'Staff salaries — June 2026',            'amount' => 85000.00, 'date' => Carbon::now()->startOfMonth()->addDays(4)],
            ['category' => 'Salaries',     'description' => 'Staff salaries — May 2026',             'amount' => 85000.00, 'date' => Carbon::now()->subMonth()->startOfMonth()->addDays(4)],
            ['category' => 'Salaries',     'description' => 'Staff salaries — April 2026',           'amount' => 80000.00, 'date' => Carbon::now()->subMonths(2)->startOfMonth()->addDays(4)],

            // ── Equipment ─────────────────────────────────────────────────────
            ['category' => 'Equipment',    'description' => 'MikroTik hAP ac² routers x5 (client installs)',  'amount' => 22500.00, 'date' => Carbon::now()->subDays(20)],
            ['category' => 'Equipment',    'description' => 'CAT6 cable — 500m roll',                         'amount' => 6500.00,  'date' => Carbon::now()->subDays(35)],
            ['category' => 'Equipment',    'description' => 'TP-Link outdoor CPE — 4 units',                  'amount' => 18000.00, 'date' => Carbon::now()->subDays(50)],
            ['category' => 'Equipment',    'description' => 'UPS battery replacement — server room',          'amount' => 12000.00, 'date' => Carbon::now()->subDays(65)],

            // ── Maintenance ───────────────────────────────────────────────────
            ['category' => 'Maintenance',  'description' => 'Tower maintenance — Bungoma HQ mast',            'amount' => 15000.00, 'date' => Carbon::now()->subDays(15)],
            ['category' => 'Maintenance',  'description' => 'Fibre splice repair — Webuye junction',          'amount' => 8000.00,  'date' => Carbon::now()->subDays(40)],
            ['category' => 'Maintenance',  'description' => 'Server rack servicing and cable management',     'amount' => 5000.00,  'date' => Carbon::now()->subDays(55)],

            // ── Office & admin ────────────────────────────────────────────────
            ['category' => 'Office',       'description' => 'Office rent — June 2026',                        'amount' => 18000.00, 'date' => Carbon::now()->startOfMonth()],
            ['category' => 'Office',       'description' => 'Office rent — May 2026',                         'amount' => 18000.00, 'date' => Carbon::now()->subMonth()->startOfMonth()],
            ['category' => 'Office',       'description' => 'Africa\'s Talking SMS bundle — 5000 messages',   'amount' => 4000.00,  'date' => Carbon::now()->subDays(25)],
            ['category' => 'Office',       'description' => 'Stationery and printing (receipts, forms)',       'amount' => 1500.00,  'date' => Carbon::now()->subDays(45)],
        ];

        foreach ($expenditures as $exp) {
            DB::table('expenditures')->insert([
                'category'    => $exp['category'],
                'description' => $exp['description'],
                'amount'      => $exp['amount'],
                'date'        => $exp['date']->toDateString(),
                'recorded_by' => $adminId,
                'created_at'  => $exp['date'],
                'updated_at'  => $exp['date'],
            ]);
        }

        $this->command->info('ExpenditureSeeder: ' . count($expenditures) . ' expenditures seeded.');
    }
}
