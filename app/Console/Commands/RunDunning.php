<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Billing\DunningService;
use Illuminate\Console\Command;

class RunDunning extends Command
{
    protected $signature = 'billing:run-dunning
                            {--tenant= : Run dunning for a specific tenant ID (runs all tenants when omitted)}';

    protected $description = 'Execute dunning steps for overdue invoices per tenant';

    public function handle(DunningService $dunningService): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $total = [
            'newly_overdue' => 0, 'email' => 0, 'sms' => 0,
            'suspend' => 0, 'escalate' => 0, 'skipped' => 0,
        ];

        foreach ($tenants as $tenant) {
            $summary = $dunningService->runForTenant($tenant);

            foreach ($total as $key => $value) {
                $total[$key] = $value + ($summary[$key] ?? 0);
            }

            $this->line("[{$tenant->slug}] " . json_encode($summary));
        }

        $this->info('Dunning complete: ' . json_encode($total));

        return self::SUCCESS;
    }
}