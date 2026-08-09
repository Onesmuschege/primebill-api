<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds a payment for every 'paid' invoice, tenant-aware. Idempotency keys
 * and M-Pesa receipt codes are made unique per tenant by incorporating the
 * tenant id, keeping the dedup indexes valid while staying deterministic.
 */
class PaymentSeeder extends Seeder
{
    use SeedsForTenant;

    private array $prefixes = ['QGH','QJK','QKL','QMP','QNR','QPT','QRV','QSW','QTX','QUY'];

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();

            $paidInvoices = Invoice::where('tenant_id', $tenant->id)
                ->where('status', 'paid')
                ->get();

            $seeded = 0;

            foreach ($paidInvoices as $index => $invoice) {
                if (Payment::where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->exists()) {
                    continue;
                }

                $method    = ($index % 20) <= 18 ? 'mpesa' : 'cash';
                $mpesaCode = $method === 'mpesa'
                    ? $this->mpesaCode($tenant->id, $invoice->id)
                    : null;
                $idempotent = $mpesaCode ?? ('cash-' . $tenant->id . '-' . $invoice->id);

                if ($mpesaCode && Payment::where('mpesa_code', $mpesaCode)->exists()) {
                    continue;
                }

                $paidAt = Carbon::parse($invoice->paid_at ?? $invoice->created_at)
                    ->subDays(($index * 3) % 27)
                    ->setTime(9 + ($index % 8), ($index * 11) % 60);

                try {
                    $payment = Payment::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'idempotency_key' => $idempotent],
                        [
                            'client_id'       => $invoice->client_id,
                            'invoice_id'      => $invoice->id,
                            'amount'          => $invoice->total,
                            'method'          => $method,
                            'mpesa_code'      => $mpesaCode,
                            'reference'       => $method === 'mpesa'
                                                    ? 'PRIMEBILL-' . $tenant->id . '-' . $invoice->client_id
                                                    : 'CASH-' . $tenant->id . '-' . $invoice->id,
                            'idempotency_key' => $idempotent,
                            'status'          => 'completed',
                            'recorded_by'     => $admin?->id,
                        ]
                    );
                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'payments_mpesa_code_unique')) {
                        continue;
                    }

                    throw $e;
                }

                $payment->forceFill([
                    'created_at' => $paidAt,
                    'updated_at' => $paidAt,
                ])->save();

                $seeded++;
            }

            $this->command->line("  [{$tenant->slug}] {$seeded} payments seeded.");
        });
    }

    private function mpesaCode(int $tenantId, int $invoiceId): string
    {
        $prefix = $this->prefixes[($tenantId + $invoiceId) % count($this->prefixes)];
        $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';
        $seed   = $tenantId * 7919 + $invoiceId * 104729 + 100000;

        for ($i = 0; $i < 7; $i++) {
            $seed = ($seed * 1664525 + 1013904223) & 0x7FFFFFFF;
            $suffix .= $chars[$seed % strlen($chars)];
        }

        return $prefix . $suffix;
    }
}
