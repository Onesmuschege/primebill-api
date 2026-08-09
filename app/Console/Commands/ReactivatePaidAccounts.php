<?php

namespace App\Console\Commands;

use App\Jobs\ActivateNetworkAccessJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Email\EmailService;
use Illuminate\Console\Command;

class ReactivatePaidAccounts extends Command
{
    protected $signature = 'billing:reactivate-paid';
    protected $description = 'Reactivate suspended accounts for clients with no overdue invoices';

    public function handle(EmailService $emailService): void
    {
        $reactivated = 0;

        // Process each tenant in isolation so the global tenant scope keeps
        // this command from ever touching another tenant's data. Outside an
        // HTTP request Tenant::current() is null and the scope silently
        // skips — so without binding a tenant here every loop would query
        // across ALL tenants.
        foreach (Tenant::query()->cursor() as $tenant) {
            Tenant::setCurrent($tenant);

            try {
                $clients = Client::where('status', 'suspended')
                    ->with('accounts')
                    ->get();

                foreach ($clients as $client) {
                    $hasOverdue = Invoice::where('client_id', $client->id)
                        ->whereIn('status', ['overdue', 'unpaid'])
                        ->where('due_date', '<', now())
                        ->exists();

                    if ($hasOverdue) {
                        continue;
                    }

                    foreach ($client->accounts()->where('status', 'suspended')->get() as $account) {
                        $account->update(['status' => 'active']);
                        ActivateNetworkAccessJob::dispatch($account->id, $tenant->id);
                    }

                    $client->update(['status' => 'active']);
                    $emailService->accountActivatedEmail($client);
                    $reactivated++;
                }
            } finally {
                Tenant::setCurrent(null);
            }
        }

        $this->info("Reactivated {$reactivated} clients.");
    }
}
