<?php

namespace App\Console\Commands;

use App\Jobs\ActivateNetworkAccessJob;
use App\Jobs\SuspendNetworkAccessJob;
use App\Models\Invoice;
use App\Services\Sms\SmsService;
use Illuminate\Console\Command;

class SuspendOverdueAccounts extends Command
{
    protected $signature   = 'billing:suspend-overdue';
    protected $description = 'Suspend accounts with overdue invoices';

    public function handle(SmsService $smsService): void
    {
        $overdueInvoices = Invoice::where('status', 'overdue')
                                  ->where('due_date', '<', now()->subDays(3))
                                  ->with('client.accounts')
                                  ->get();

        $count = 0;
        foreach ($overdueInvoices as $invoice) {
            $accounts = $invoice->client->accounts()->where('status', 'active')->get();

            foreach ($accounts as $account) {
                $account->update(['status' => 'suspended']);
                SuspendNetworkAccessJob::dispatch($account->id);
            }

            $invoice->client->update(['status' => 'suspended']);

            $smsService->send(
                $invoice->client->phone,
                "Dear {$invoice->client->first_name}, your account has been suspended due to overdue invoice of KES {$invoice->total}. Pay to reactivate.",
                $invoice->client_id
            );

            $count++;
        }

        $this->info("Suspended {$count} accounts.");
    }
}
