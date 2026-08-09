<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds ledger_entries for every invoice and its payments, tenant-aware.
 * Uses the Eloquent model so the BelongsToTenant trait auto-fills tenant_id
 * from the bound current tenant.
 *
 * Double-entry:
 *   invoice created -> invoice_debit  (client owes)
 *   payment made    -> payment_credit (client paid)
 */
class LedgerSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $count = 0;

            Invoice::where('tenant_id', $tenant->id)->with('payments')->get()->each(function (Invoice $invoice) use (&$count) {
                LedgerEntry::updateOrCreate(
                    [
                        'tenant_id'  => $invoice->tenant_id,
                        'invoice_id' => $invoice->id,
                        'entry_type' => 'invoice_debit',
                    ],
                    [
                        'client_id'   => $invoice->client_id,
                        'payment_id'  => null,
                        'amount'      => $invoice->total,
                        'currency'    => 'KES',
                        'description' => "Invoice {$invoice->invoice_number} - subscription charge",
                        'meta'        => ['invoice_number' => $invoice->invoice_number],
                        'recorded_by' => $invoice->created_by,
                        'created_at'  => $invoice->created_at,
                        'updated_at'  => $invoice->created_at,
                    ]
                );
                $count++;

                foreach ($invoice->payments as $payment) {
                    LedgerEntry::updateOrCreate(
                        [
                            'tenant_id'  => $invoice->tenant_id,
                            'payment_id' => $payment->id,
                            'entry_type' => 'payment_credit',
                        ],
                        [
                            'client_id'   => $invoice->client_id,
                            'invoice_id'  => $invoice->id,
                            'amount'      => $payment->amount,
                            'currency'    => 'KES',
                            'description' => "Payment received via {$payment->method}" . ($payment->mpesa_code ? " ({$payment->mpesa_code})" : ''),
                            'meta'        => [
                                'method'     => $payment->method,
                                'mpesa_code' => $payment->mpesa_code,
                                'reference'  => $payment->reference,
                            ],
                            'recorded_by' => $payment->recorded_by,
                            'created_at'  => $payment->created_at,
                            'updated_at'  => $payment->created_at,
                        ]
                    );
                    $count++;
                }
            });

            $this->command->line("  [{$tenant->slug}] {$count} ledger entries seeded.");
        });
    }
}
