<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\SmsLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds SMS logs representing real ISP notification patterns:
 *   - Invoice due reminders
 *   - Payment confirmation
 *   - Account suspension warnings
 *   - Account activation confirmations
 *
 * ~85% sent, ~15% failed (realistic Africa's Talking delivery rate).
 */
class SmsLogSeeder extends Seeder
{
    public function run(): void
    {
        $count   = 0;
        $clients = Client::all();
        $invoices = Invoice::with('client')->get()->keyBy('client_id');

        foreach ($clients as $client) {
            $invoice = $invoices->get($client->id);
            $amount  = $invoice ? number_format($invoice->total, 2) : '2,900.00';
            $dueDate = $invoice ? Carbon::parse($invoice->due_date)->format('d M Y') : Carbon::now()->addDays(7)->format('d M Y');

            $messages = $this->buildMessages($client, $amount, $dueDate);

            foreach ($messages as $msg) {
                $sentAt  = Carbon::now()->subDays(rand(0, 60));
                $status  = $msg['force_fail'] ? 'failed' : (rand(1, 100) <= 85 ? 'sent' : 'failed');

                SmsLog::create([
                    'client_id'        => $client->id,
                    'phone'            => $client->phone,
                    'message'          => $msg['text'],
                    'status'           => $status,
                    'gateway_response' => $status === 'sent'
                        ? json_encode([
                            'status'    => 'success',
                            'messageId' => 'ATXid_' . strtoupper(Str::random(16)),
                            'cost'      => 'KES 0.8000',
                            'number'    => $client->phone,
                          ])
                        : json_encode([
                            'status'  => 'error',
                            'message' => 'InvalidPhoneNumber',
                            'number'  => $client->phone,
                          ]),
                    'gateway'    => 'africas_talking',
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);

                $count++;
            }
        }

        $this->command->info("SmsLogSeeder: {$count} SMS logs seeded.");
    }

    private function buildMessages(object $client, string $amount, string $dueDate): array
    {
        $name = $client->first_name;

        $all = [
            [
                'text'       => "Dear {$name}, your PrimeBill invoice of Ksh {$amount} is due on {$dueDate}. Pay via M-Pesa Paybill 400200, Account: {$client->phone}. Ignore if paid. PrimeBill.",
                'force_fail' => false,
            ],
            [
                'text'       => "Dear {$name}, payment of Ksh {$amount} received. Thank you! Your internet account is active. For support call 0700000000. PrimeBill Network Solutions.",
                'force_fail' => false,
            ],
            [
                'text'       => "Dear {$name}, your PrimeBill subscription expires in 3 days. Renew now to avoid service interruption. Pay Ksh {$amount} via M-Pesa Paybill 400200. PrimeBill.",
                'force_fail' => $client->status === 'disabled', // force fail for disabled clients
            ],
        ];

        // Suspended/inactive clients also get a suspension notice
        if (in_array($client->status, ['suspended', 'inactive', 'disabled'])) {
            $all[] = [
                'text'       => "Dear {$name}, your PrimeBill account has been suspended due to non-payment. Pay outstanding Ksh {$amount} to restore service. Call 0700000000. PrimeBill.",
                'force_fail' => false,
            ];
        }

        return $all;
    }
}
