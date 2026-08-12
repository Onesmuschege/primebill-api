<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature   = 'billing:generate-invoices';
    protected $description = 'Generate monthly invoices for all active accounts';

    public function handle(InvoiceService $invoiceService): void
    {
        $accounts = ClientAccount::with('client', 'plan')
                                 ->where('status', 'active')
                                 ->whereHas('client')
                                 ->whereHas('plan')
                                 ->get();

        $count = 0;
        $skipped = 0;
        foreach ($accounts as $account) {
            // Dedup guard: skip when this client already has an open
            // (unpaid/overdue) invoice for the current cycle so repeated runs
            // never generate duplicate billings.
            $hasOpenInvoice = Invoice::where('client_id', $account->client_id)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->exists();

            if ($hasOpenInvoice) {
                $skipped++;
                continue;
            }

            $invoiceService->createInvoice([
                'client_id' => $account->client_id,
                'amount'    => $account->plan->price,
                'due_date'  => now()->addDays(7)->toDateString(),
                'status'    => 'unpaid',
            ], 1);
            $count++;
        }

        $this->info("Generated {$count} invoices ({$skipped} skipped — open invoice exists).");
    }
}
