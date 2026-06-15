<?php

namespace App\Console\Commands;

use App\Jobs\ActivateNetworkAccessJob;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Console\Command;

class ReactivatePaidAccounts extends Command
{
    protected $signature = 'billing:reactivate-paid';
    protected $description = 'Reactivate suspended accounts for clients with no overdue invoices';

    public function handle(): void
    {
        $clients = Client::where('status', 'suspended')
            ->with('accounts')
            ->get();

        $reactivated = 0;
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
                ActivateNetworkAccessJob::dispatch($account->id);
            }

            $client->update(['status' => 'active']);
            $reactivated++;
        }

        $this->info("Reactivated {$reactivated} clients.");
    }
}
