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
            ->orderBy('id')
            ->get();

        $monthsAgoSequence = $this->buildMonthsAgoSequence($paidInvoices->count());

        $seeded  = 0;
        $skipped = 0;

        foreach ($paidInvoices as $index => $invoice) {
            // Avoid re-creating if a payment already exists for this invoice.
            if (Payment::where('invoice_id', $invoice->id)->exists()) {
                $skipped++;
                continue;
            }

            $method     = $this->pickMethod($invoice->id);
            $mpesaCode  = $method !== 'cash' ? $this->generateMpesaCode($invoice->id) : null;
            $idempotKey = $mpesaCode ?? (string) Str::uuid();

            // Spread payments across the last 12 months (growth curve) rather
            // than clustering around invoice->paid_at, which only spans ~90 days.
            $paidAt = Carbon::now()
                ->subMonths($monthsAgoSequence[$index])
                ->subDays(($invoice->id * 3) % 27)
                ->setTime(9 + ($invoice->id % 8), ($invoice->id * 11) % 60);

            $payment = Payment::updateOrCreate(
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
                ]
            );

            // created_at/updated_at aren't mass-assignable — forceFill bypasses
            // that and marks them dirty, so Eloquent won't overwrite with now().
            $payment->forceFill([
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ])->save();

            $seeded++;
        }

        $this->command->info("PaymentSeeder: {$seeded} payments seeded, {$skipped} skipped (already exist).");
    }

    /**
     * Build a list of "months ago" values, one per payment, following the
     * same growth curve as ClientSeeder — slower activity further in the
     * past, accelerating toward the present. Always returns exactly $total values.
     */
    private function buildMonthsAgoSequence(int $total): array
    {
        $weights = [11 => 1, 10 => 1, 9 => 2, 8 => 2, 7 => 2, 6 => 3, 5 => 3, 4 => 4, 3 => 4, 2 => 5, 1 => 6, 0 => 7];
        $weightSum = array_sum($weights);

        $sequence = [];
        foreach ($weights as $monthsAgo => $weight) {
            $count = (int) round($total * $weight / $weightSum);
            for ($i = 0; $i < $count; $i++) {
                $sequence[] = $monthsAgo;
            }
        }

        while (count($sequence) > $total) {
            array_pop($sequence);
        }
        while (count($sequence) < $total) {
            $sequence[] = 0;
        }

        return $sequence;
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