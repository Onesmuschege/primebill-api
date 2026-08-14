<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds payment allocations that link each payment to the invoice it was
 * recorded against. Every allocation amount equals the payment amount so
 * that allocation totals never exceed the underlying payment. Idempotent
 * on tenant + payment_id + invoice_id.
 */
class PaymentAllocationSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $payments = Payment::where('tenant_id', $tenant->id)->get();

            if ($payments->isEmpty()) {
                $this->command->warn("PaymentAllocationSeeder [{$tenant->slug}]: No payments found. Skipping.");
                return;
            }

            $created = 0;

            foreach ($payments as $idx => $payment) {
                // Idempotency: skip if already fully allocated.
                $existing = PaymentAllocation::where('tenant_id', $tenant->id)
                    ->where('payment_id', $payment->id)
                    ->where('invoice_id', $payment->invoice_id)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $invoice = Invoice::where('tenant_id', $tenant->id)->find($payment->invoice_id);

                PaymentAllocation::create([
                    'tenant_id'    => $tenant->id,
                    'payment_id'   => $payment->id,
                    'invoice_id'   => $payment->invoice_id,
                    'client_id'    => $payment->client_id,
                    'amount'       => $payment->amount,
                    'currency'     => 'KES',
                    'status'       => 'allocated',
                    'reference'    => 'ALLOC-' . $tenant->id . '-' . $payment->id,
                    'meta'         => ['seed' => true, 'payment_method' => $payment->method],
                    'recorded_by'  => $admin?->id,
                    'created_at'   => $payment->created_at ?? now(),
                    'updated_at'   => $payment->created_at ?? now(),
                ]);

                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} payment allocations seeded.");
        });

        $this->command->info('PaymentAllocationSeeder: complete.');
    }
}
