<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds invoices for all clients with a realistic status distribution.
 *
 * Pattern per client:
 *   Active clients    — 3 invoices: 2 paid (historical), 1 unpaid (current month)
 *   Suspended clients — 3 invoices: 1 paid, 1 overdue (trigger for suspension), 1 unpaid
 *   Inactive clients  — 2 invoices: 1 paid, 1 cancelled
 *   Disabled clients  — 2 invoices: both overdue
 *
 * Invoice numbers: INV-YYYY-XXXXXX (zero-padded sequential per client)
 * Tax: 16% VAT applied to all invoices (Kenya standard)
 * Due date: 30 days from invoice creation
 *
 * Safe to re-run: uses updateOrCreate on invoice_number (unique key).
 */
class InvoiceSeeder extends Seeder
{
    // KES amounts matching typical Kenyan ISP plan pricing
    private array $planAmounts = [500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 5000];

    public function run(): void
    {
        $adminId = User::where('email', 'admin@primebill.co.ke')->value('id');
        $counter = 1;

        $clients = Client::all();

        foreach ($clients as $client) {
            $invoices = $this->buildInvoicesForClient($client, $adminId, $counter);
            foreach ($invoices as $invoice) {
                Invoice::updateOrCreate(
                    ['invoice_number' => $invoice['invoice_number']],
                    $invoice
                );
                $counter++;
            }
        }

        $this->command->info('InvoiceSeeder: ' . ($counter - 1) . ' invoices seeded.');
    }

    private function buildInvoicesForClient(Client $client, ?int $adminId, int &$counter): array
    {
        $amount = $this->planAmounts[$client->id % count($this->planAmounts)];
        $tax    = round($amount * 0.16, 2);
        $total  = $amount + $tax;

        return match ($client->status) {
            'active'    => $this->activeClientInvoices($client, $adminId, $amount, $tax, $total, $counter),
            'suspended' => $this->suspendedClientInvoices($client, $adminId, $amount, $tax, $total, $counter),
            'inactive'  => $this->inactiveClientInvoices($client, $adminId, $amount, $tax, $total, $counter),
            'disabled'  => $this->disabledClientInvoices($client, $adminId, $amount, $tax, $total, $counter),
            default     => [],
        };
    }

    private function activeClientInvoices(Client $client, ?int $adminId, float $amount, float $tax, float $total, int &$counter): array
    {
        $base = ['client_id' => $client->id, 'amount' => $amount, 'tax' => $tax, 'total' => $total, 'created_by' => $adminId];

        return [
            // Two months ago — paid
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter),
                'status'         => 'paid',
                'due_date'       => Carbon::now()->subDays(60),
                'paid_at'        => Carbon::now()->subDays(58),
                'created_at'     => Carbon::now()->subDays(62),
                'updated_at'     => Carbon::now()->subDays(58),
            ]),
            // Last month — paid
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter + 1),
                'status'         => 'paid',
                'due_date'       => Carbon::now()->subDays(30),
                'paid_at'        => Carbon::now()->subDays(27),
                'created_at'     => Carbon::now()->subDays(32),
                'updated_at'     => Carbon::now()->subDays(27),
            ]),
            // This month — unpaid (current)
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter + 2),
                'status'         => 'unpaid',
                'due_date'       => Carbon::now()->addDays(15),
                'paid_at'        => null,
                'created_at'     => Carbon::now()->subDays(2),
                'updated_at'     => Carbon::now()->subDays(2),
            ]),
        ];
    }

    private function suspendedClientInvoices(Client $client, ?int $adminId, float $amount, float $tax, float $total, int &$counter): array
    {
        $base = ['client_id' => $client->id, 'amount' => $amount, 'tax' => $tax, 'total' => $total, 'created_by' => $adminId];

        return [
            // Oldest — paid
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter),
                'status'         => 'paid',
                'due_date'       => Carbon::now()->subDays(90),
                'paid_at'        => Carbon::now()->subDays(88),
                'created_at'     => Carbon::now()->subDays(92),
                'updated_at'     => Carbon::now()->subDays(88),
            ]),
            // Overdue — this is what triggered suspension
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter + 1),
                'status'         => 'overdue',
                'due_date'       => Carbon::now()->subDays(35),
                'paid_at'        => null,
                'notes'          => 'Account suspended due to non-payment.',
                'created_at'     => Carbon::now()->subDays(65),
                'updated_at'     => Carbon::now()->subDays(35),
            ]),
            // Still unpaid — generated but account suspended
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter + 2),
                'status'         => 'unpaid',
                'due_date'       => Carbon::now()->subDays(5),
                'paid_at'        => null,
                'created_at'     => Carbon::now()->subDays(32),
                'updated_at'     => Carbon::now()->subDays(32),
            ]),
        ];
    }

    private function inactiveClientInvoices(Client $client, ?int $adminId, float $amount, float $tax, float $total, int &$counter): array
    {
        $base = ['client_id' => $client->id, 'amount' => $amount, 'tax' => $tax, 'total' => $total, 'created_by' => $adminId];

        return [
            // Paid — last active invoice
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter),
                'status'         => 'paid',
                'due_date'       => Carbon::now()->subDays(75),
                'paid_at'        => Carbon::now()->subDays(73),
                'created_at'     => Carbon::now()->subDays(77),
                'updated_at'     => Carbon::now()->subDays(73),
            ]),
            // Cancelled — generated after account went inactive
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter + 1),
                'status'         => 'cancelled',
                'due_date'       => Carbon::now()->subDays(45),
                'paid_at'        => null,
                'notes'          => 'Client requested account deactivation.',
                'created_at'     => Carbon::now()->subDays(47),
                'updated_at'     => Carbon::now()->subDays(44),
            ]),
        ];
    }

    private function disabledClientInvoices(Client $client, ?int $adminId, float $amount, float $tax, float $total, int &$counter): array
    {
        $base = ['client_id' => $client->id, 'amount' => $amount, 'tax' => $tax, 'total' => $total, 'created_by' => $adminId];

        return [
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter),
                'status'         => 'overdue',
                'due_date'       => Carbon::now()->subDays(95),
                'paid_at'        => null,
                'created_at'     => Carbon::now()->subDays(97),
                'updated_at'     => Carbon::now()->subDays(95),
            ]),
            array_merge($base, [
                'invoice_number' => $this->invoiceNum($counter + 1),
                'status'         => 'overdue',
                'due_date'       => Carbon::now()->subDays(65),
                'paid_at'        => null,
                'created_at'     => Carbon::now()->subDays(67),
                'updated_at'     => Carbon::now()->subDays(65),
            ]),
        ];
    }

    private function invoiceNum(int $n): string
    {
        return 'INV-' . date('Y') . '-' . str_pad($n, 6, '0', STR_PAD_LEFT);
    }
}