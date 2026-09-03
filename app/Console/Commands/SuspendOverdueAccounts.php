<?php

namespace App\Console\Commands;

use App\Jobs\SuspendNetworkAccessJob;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Email\EmailService;
use App\Services\Sms\SmsService;
use Illuminate\Console\Command;

class SuspendOverdueAccounts extends Command
{
    protected $signature   = 'billing:suspend-overdue';
    protected $description = 'Suspend accounts with overdue invoices';

    public function handle(SmsService $smsService, EmailService $emailService): void
    {
        $count = 0;

        // Process each tenant in isolation so the global tenant scope keeps
        // this command from ever touching another tenant's data. Outside an
        // HTTP request Tenant::current() is null and the scope silently
        // skips — so without binding a tenant here every loop would query
        // across ALL tenants.
        foreach (Tenant::query()->cursor() as $tenant) {
            Tenant::setCurrent($tenant);

            try {
                $overdueInvoices = Invoice::where('status', 'overdue')
                    ->where('due_date', '<', now()->subDays(3))
                    ->with('client.accounts')
                    ->get();

                foreach ($overdueInvoices as $invoice) {
                    $accounts = $invoice->client->accounts()->where('status', 'active')->get();

                    foreach ($accounts as $account) {
                        // Billing suspension flows through the lifecycle
                        // authority (queued) so `service_state`, `status` and
                        // the suspension classification stay consistent.
                        SuspendNetworkAccessJob::dispatch(
                            $account->id,
                            $tenant->id,
                            ClientAccount::SUSPENSION_BILLING,
                            "Overdue invoice {$invoice->invoice_number}"
                        );
                    }

                    $invoice->client->update(['status' => 'suspended']);

                    $smsService->send(
                        $invoice->client->phone,
                        "Dear {$invoice->client->first_name}, your account has been suspended due to overdue invoice of KES {$invoice->total}. Pay to reactivate.",
                        $invoice->client_id
                    );

                    $emailService->accountSuspendedEmail($invoice->client);

                    $count++;
                }
            } finally {
                Tenant::setCurrent(null);
            }
        }

        $this->info("Suspended {$count} accounts.");
    }
}
