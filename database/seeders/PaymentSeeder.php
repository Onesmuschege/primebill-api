<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds payments for every invoice with status 'paid'.
 *
 * Payment method distribution (mimics real Kenyan ISP collections):
 *   ~75% M-Pesa STK Push
 *   ~20% M-Pesa C2B paybill
 *   ~5%  Cash (walk-in)
 *
 * M-Pesa receipt numbers: real Safaricom format — Q + 9 uppercase alphanumeric chars.
 * Idempotency keys: receipt number for M-Pesa, UUID for cash.
 *
 * Safe to re-run: uses updateOrCreate on idempotency_key (unique key).
 * Skips invoices that already have a payment recorded.
 */
class PaymentSeeder extends Seeder
{
    // Realistic Safaricom receipt number prefix pool
    private array $mpesaPrefixes = ['QGH', 'QJK', 'QKL', 'QMP', 'QNR', 'QPT', 'QRV', 'QSW', 'QTX', 'QUY'];

    public function run(): void
    {
        $adminId = User::where('email', 'admin@primebill.co.ke')->value('id');

        $paidInvoices = Invoice::where('status', 'paid')
            ->with('client')
            ->get();

        $seeded  = 0;
        $skipped = 0;

        foreach ($paidInvoices as $invoice) {
            // Avoid re-creating if a payment already exists for this invoice.
            if (Payment::where('invoice_id', $invoice->id)->exists()) {
                $skipped++;
                continue;
            }

            $method     = $this->pickMethod($invoice->id);
            $mpesaCode  = $method !== 'cash' ? $this->generateMpesaCode($invoice->id) : null;
            $idempotKey = $mpesaCode ?? (string) Str::uuid();

            Payment::updateOrCreate(
                ['idempotency_key' => $idempotKey],
                [
                    'client_id'       => $invoice->client_id,
                    'invoice_id'      => $invoice->id,
                    'amount'          => $invoice->total,
                    'method'          => $method,
                    'mpesa_code'      => $mpesaCode,
                    'reference'       => $method !== 'cash'
                                            ? 'PRIMEBILL-' . $invoice->client_id
                                            : 'CASH-RECEIPT-' . str_pad($invoice->id, 5, '0', STR_PAD_LEFT),
                    'idempotency_key' => $idempotKey,
                    'status'          => 'completed',
                    'recorded_by'     => $adminId,
                    'created_at'      => $invoice->paid_at ?? Carbon::now(),
                    'updated_at'      => $invoice->paid_at ?? Carbon::now(),
                ]
            );

            $seeded++;
        }

        $this->command->info("PaymentSeeder: {$seeded} payments seeded, {$skipped} skipped (already exist).");
    }

    /**
     * Deterministic method selection based on invoice ID.
     * Produces ~75% mpesa, ~20% mpesa (c2b style, same method label), ~5% cash.
     * In the payments table both STK and C2B are stored as 'mpesa' — they're
     * distinguished by whether an MpesaTransaction record exists.
     */
    private function pickMethod(int $invoiceId): string
    {
        $mod = $invoiceId % 20;
        if ($mod <= 14) return 'mpesa';   // 75% — STK push
        if ($mod <= 18) return 'mpesa';   // 20% — C2B (same method value)
        return 'cash';                    //  5% — cash
    }

    /**
     * Generate a realistic Safaricom M-Pesa receipt number.
     * Format: [PREFIX][9 alphanumeric chars] e.g. QGHT4R7KM2X
     * Made deterministic per invoice so re-runs produce the same codes
     * and don't collide with the unique index on payments.mpesa_code.
     */
    private function generateMpesaCode(int $invoiceId): string
    {
        $prefix  = $this->mpesaPrefixes[$invoiceId % count($this->mpesaPrefixes)];
        $chars   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Safaricom charset (no I, O, 0, 1)
        $suffix  = '';

        // Seed the sequence deterministically from invoice ID so codes are
        // reproducible and unique across the 40-client dataset.
        $seed = $invoiceId * 7919; // Prime multiplier for spread
        for ($i = 0; $i < 9; $i++) {
            $seed  = ($seed * 1664525 + 1013904223) & 0x7FFFFFFF; // LCG
            $suffix .= $chars[$seed % strlen($chars)];
        }

        return $prefix . $suffix;
    }
}