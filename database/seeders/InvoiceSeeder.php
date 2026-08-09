<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds invoices for all clients per tenant with a realistic status
 * distribution (paid/unpaid/overdue/cancelled). Invoice numbers are prefixed
 * with the tenant id so they stay globally unique while remaining per-tenant
 * readable. Amounts derive from the client's plan price so billing data is
 * internally consistent.
 */
class InvoiceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();
            $counter = 1;

            Client::where('tenant_id', $tenant->id)->each(function (Client $client) use ($tenant, $admin, &$counter) {
                $plan = Plan::where('tenant_id', $tenant->id)->first();
                $amount = $plan?->price ?? 2900;
                $tax    = round($amount * ($tenant->tax_rate / 100), 2);
                $total  = round($amount + $tax, 2);

                $invoices = $this->buildInvoices($client, $tenant, $admin?->id, $amount, $tax, $total, $counter);

                foreach ($invoices as $invoice) {
                    Invoice::updateOrCreate(
                        ['invoice_number' => $invoice['invoice_number']],
                        $invoice
                    );
                    $counter++;
                }
            });
        });

        $this->command->info('InvoiceSeeder: invoices seeded per tenant.');
    }

    private function buildInvoices(Client $client, Tenant $tenant, ?int $adminId, float $amount, float $tax, float $total, int &$counter): array
    {
        $base = [
            'client_id' => $client->id,
            'amount'    => $amount,
            'tax'       => $tax,
            'total'     => $total,
            'created_by'=> $adminId,
        ];

        $num = fn (int $n) => 'INV-' . $tenant->id . '-' . date('Y') . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);

        return match ($client->status) {
            'suspended' => [
                array_merge($base, ['invoice_number' => $num($counter), 'status' => 'paid',     'due_date' => Carbon::now()->subDays(90), 'paid_at' => Carbon::now()->subDays(88), 'created_at' => Carbon::now()->subDays(92)]),
                array_merge($base, ['invoice_number' => $num($counter + 1), 'status' => 'overdue', 'due_date' => Carbon::now()->subDays(35), 'paid_at' => null, 'notes' => 'Account suspended due to non-payment.', 'created_at' => Carbon::now()->subDays(65)]),
                array_merge($base, ['invoice_number' => $num($counter + 2), 'status' => 'unpaid',  'due_date' => Carbon::now()->subDays(5),  'paid_at' => null, 'created_at' => Carbon::now()->subDays(32)]),
            ],
            'inactive' => [
                array_merge($base, ['invoice_number' => $num($counter), 'status' => 'paid',     'due_date' => Carbon::now()->subDays(75), 'paid_at' => Carbon::now()->subDays(73), 'created_at' => Carbon::now()->subDays(77)]),
                array_merge($base, ['invoice_number' => $num($counter + 1), 'status' => 'cancelled', 'due_date' => Carbon::now()->subDays(45), 'paid_at' => null, 'notes' => 'Client deactivated.', 'created_at' => Carbon::now()->subDays(47)]),
            ],
            'disabled' => [
                array_merge($base, ['invoice_number' => $num($counter), 'status' => 'overdue', 'due_date' => Carbon::now()->subDays(95), 'paid_at' => null, 'created_at' => Carbon::now()->subDays(97)]),
                array_merge($base, ['invoice_number' => $num($counter + 1), 'status' => 'overdue', 'due_date' => Carbon::now()->subDays(65), 'paid_at' => null, 'created_at' => Carbon::now()->subDays(67)]),
            ],
            default => [
                array_merge($base, ['invoice_number' => $num($counter), 'status' => 'paid',   'due_date' => Carbon::now()->subDays(60), 'paid_at' => Carbon::now()->subDays(58), 'created_at' => Carbon::now()->subDays(62)]),
                array_merge($base, ['invoice_number' => $num($counter + 1), 'status' => 'paid',   'due_date' => Carbon::now()->subDays(30), 'paid_at' => Carbon::now()->subDays(27), 'created_at' => Carbon::now()->subDays(32)]),
                array_merge($base, ['invoice_number' => $num($counter + 2), 'status' => 'unpaid', 'due_date' => Carbon::now()->addDays(15), 'paid_at' => null, 'created_at' => Carbon::now()->subDays(2)]),
            ],
        };
    }
}
