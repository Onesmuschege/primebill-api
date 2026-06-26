<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ledger_entries for every invoice and its payment.
 * Double-entry pattern:
 *   invoice created → invoice_debit  (client owes money)
 *   payment made    → payment_credit (client paid)
 */
class LedgerSeeder extends Seeder
{
    public function run(): void
    {
        $count = 0;

        Invoice::with('payments')->get()->each(function (Invoice $invoice) use (&$count) {
            // Invoice debit entry
            DB::table('ledger_entries')->insertOrIgnore([
                'client_id'   => $invoice->client_id,
                'invoice_id'  => $invoice->id,
                'payment_id'  => null,
                'entry_type'  => 'invoice_debit',
                'amount'      => $invoice->total,
                'currency'    => 'KES',
                'description' => "Invoice {$invoice->invoice_number} — subscription charge",
                'meta'        => json_encode(['invoice_number' => $invoice->invoice_number]),
                'recorded_by' => $invoice->created_by,
                'created_at'  => $invoice->created_at,
                'updated_at'  => $invoice->created_at,
            ]);
            $count++;

            // Payment credit entry for each payment
            foreach ($invoice->payments as $payment) {
                DB::table('ledger_entries')->insertOrIgnore([
                    'client_id'   => $invoice->client_id,
                    'invoice_id'  => $invoice->id,
                    'payment_id'  => $payment->id,
                    'entry_type'  => 'payment_credit',
                    'amount'      => $payment->amount,
                    'currency'    => 'KES',
                    'description' => "Payment received via {$payment->method}" . ($payment->mpesa_code ? " ({$payment->mpesa_code})" : ''),
                    'meta'        => json_encode([
                        'method'     => $payment->method,
                        'mpesa_code' => $payment->mpesa_code,
                        'reference'  => $payment->reference,
                    ]),
                    'recorded_by' => $payment->recorded_by,
                    'created_at'  => $payment->created_at,
                    'updated_at'  => $payment->created_at,
                ]);
                $count++;
            }
        });

        $this->command->info("LedgerSeeder: {$count} ledger entries seeded.");
    }
}
