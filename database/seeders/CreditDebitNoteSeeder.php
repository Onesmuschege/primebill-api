<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds credit notes (reduce what a client owes) and debit notes (increase
 * what a client owes) against real invoices. Idempotent on the unique
 * note-number fields.
 */
class CreditDebitNoteSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $invoices = Invoice::where('tenant_id', $tenant->id)
                ->whereIn('status', ['paid', 'unpaid', 'overdue'])
                ->get();

            if ($invoices->isEmpty()) {
                $this->command->warn("CreditDebitNoteSeeder [{$tenant->slug}]: No suitable invoices found. Skipping.");
                return;
            }

            $created = 0;

            // Credit notes — reductions, mostly applied against paid invoices.
            for ($i = 0; $i < 4; $i++) {
                $invoice = $invoices[$i % $invoices->count()];
                $number = 'CN-' . $tenant->id . '-' . date('Y') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                $scenario = ['applied', 'applied', 'issued', 'reversed'][$i];
                $amount = round((float) $invoice->total * 0.1, 2);
                $issuedAt = Carbon::parse($invoice->created_at)->addDays(3);

                CreditNote::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'credit_note_number' => $number],
                    [
                        'tenant_id' => $tenant->id,
                        'client_id' => $invoice->client_id,
                        'invoice_id' => $invoice->id,
                        'credit_note_number' => $number,
                        'amount' => $amount,
                        'currency' => 'KES',
                        'status' => $scenario,
                        'reason' => 'Service credit / discount adjustment',
                        'notes' => 'Seeded credit note (' . $scenario . ').',
                        'reference' => (string) \Illuminate\Support\Str::uuid(),
                        'reversed_by' => $scenario === 'reversed' ? $admin?->id : null,
                        'reversed_at' => $scenario === 'reversed' ? $issuedAt->copy()->addDays(2) : null,
                        'created_by' => $admin?->id,
                        'created_at' => $issuedAt,
                        'updated_at' => $issuedAt,
                    ]
                );
                $created++;
            }

            // Debit notes — increases (late fees, equipment charges).
            for ($i = 0; $i < 3; $i++) {
                $invoice = $invoices[($i + 2) % $invoices->count()];
                $number = 'DN-' . $tenant->id . '-' . date('Y') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                $scenario = ['issued', 'applied', 'draft'][$i];
                $amount = 500.00 + ($i * 100);
                $issuedAt = Carbon::parse($invoice->created_at)->addDays(4);

                DebitNote::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'debit_note_number' => $number],
                    [
                        'tenant_id' => $tenant->id,
                        'client_id' => $invoice->client_id,
                        'invoice_id' => $invoice->id,
                        'debit_note_number' => $number,
                        'amount' => $amount,
                        'currency' => 'KES',
                        'status' => $scenario,
                        'reason' => 'Late payment fee',
                        'notes' => 'Seeded debit note (' . $scenario . ').',
                        'reference' => (string) \Illuminate\Support\Str::uuid(),
                        'created_by' => $admin?->id,
                        'created_at' => $issuedAt,
                        'updated_at' => $issuedAt,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} credit & debit notes seeded.");
        });

        $this->command->info('CreditDebitNoteSeeder: complete.');
    }
}
