<?php

namespace Database\Seeders;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds refunds against real completed payments. Refund amounts never
 * exceed the source payment amount. Idempotent on tenant + refund_number.
 */
class RefundSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $payments = Payment::where('tenant_id', $tenant->id)->where('status', 'completed')->get();
            if ($payments->isEmpty()) {
                $this->command->warn("RefundSeeder [{$tenant->slug}]: No completed payments found. Skipping.");
                return;
            }

            $created = 0;
            $take = min(4, $payments->count());

            for ($i = 0; $i < $take; $i++) {
                $payment = $payments->random();
                $amount = round((float) $payment->amount * 0.25, 2);

                $scenario = ['completed', 'completed', 'pending', 'reversed'][$i];

                $refundNumber = 'RF-' . $tenant->id . '-' . date('Y') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

                $createdAt = Carbon::parse($payment->created_at)->addDays(2);

                Refund::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'refund_number' => $refundNumber],
                    [
                        'tenant_id' => $tenant->id,
                        'client_id' => $payment->client_id,
                        'payment_id' => $payment->id,
                        'invoice_id' => $payment->invoice_id,
                        'refund_number' => $refundNumber,
                        'amount' => $amount,
                        'currency' => 'KES',
                        'method' => 'mpesa',
                        'reference' => 'REF-' . $payment->id,
                        'status' => $scenario,
                        'reason' => 'Duplicate payment adjustment',
                        'reference_uuid' => (string) Str::uuid(),
                        'reversed_by' => $scenario === 'reversed' ? $admin?->id : null,
                        'reversed_at' => $scenario === 'reversed' ? $createdAt->copy()->addDays(1) : null,
                        'recorded_by' => $admin?->id,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} refunds seeded.");
        });

        $this->command->info('RefundSeeder: complete.');
    }
}
