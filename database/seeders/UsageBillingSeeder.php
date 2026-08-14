<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\UsageBillingRecord;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds usage-billing (FUP overage) records for real client accounts,
 * optionally linked to an invoice. Idempotent via a per-tenant guard.
 */
class UsageBillingSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $accounts = ClientAccount::where('tenant_id', $tenant->id)->get();
            $invoices = Invoice::where('tenant_id', $tenant->id)->get();

            if ($accounts->isEmpty()) {
                $this->command->warn("UsageBillingSeeder [{$tenant->slug}]: No client accounts found. Skipping.");
                return;
            }

            if (UsageBillingRecord::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Usage billing records already present — skipped.");
                return;
            }

            $created = 0;
            $take = min(30, $accounts->count());
            $period = now()->subMonth()->format('Y-m');

            foreach ($accounts->take($take) as $index => $account) {
                $scenario = ['pending', 'invoiced', 'waived'][$index % 3];
                $bytesIncluded = 100 * 1024 * 1024 * 1024; // 100 GB plan
                // Some generate overage, some stay under.
                $ratio = 0.5 + (($index * 37) % 100) / 100; // 0.5x - 1.5x
                $bytesUsed = (int) round($bytesIncluded * $ratio);

                $invoice = ($scenario === 'invoiced' && $invoices->isNotEmpty())
                    ? $invoices[$index % $invoices->count()]
                    : null;

                $overageBytes = max(0, $bytesUsed - $bytesIncluded);
                $ratePerGb = 100.00;
                $overageGb = $overageBytes / (1024 * 1024 * 1024);
                $overageAmount = round($overageGb * $ratePerGb, 2);

                UsageBillingRecord::create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $account->client_id,
                    'client_account_id' => $account->id,
                    'invoice_id' => $invoice?->id,
                    'billing_period' => $period,
                    'bytes_used' => $bytesUsed,
                    'bytes_included' => $bytesIncluded,
                    'bytes_overage' => $overageBytes,
                    'rate_per_gb' => $ratePerGb,
                    'overage_amount' => $overageAmount,
                    'status' => $scenario,
                    'meta' => ['username' => $account->username, 'seed' => true],
                    'created_at' => Carbon::now()->subDays(20 - $index),
                    'updated_at' => Carbon::now()->subDays(20 - $index),
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} usage billing records seeded.");
        });

        $this->command->info('UsageBillingSeeder: complete.');
    }
}
