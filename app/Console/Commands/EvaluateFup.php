<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Network\FupService;
use Illuminate\Console\Command;

class EvaluateFup extends Command
{
    protected $signature = 'network:evaluate-fup';

    protected $description = 'Evaluate FUP (fair usage) thresholds for all active accounts per tenant';

    public function handle(FupService $fupService): int
    {
        $total = ['evaluated' => 0, 'triggered' => 0];

        // Per-tenant so the global tenant scope never leaks across tenants.
        foreach (Tenant::query()->cursor() as $tenant) {
            Tenant::setCurrent($tenant);

            try {
                $result = $fupService->evaluateAll();
                foreach ($total as $key => $value) {
                    $total[$key] = $value + ($result[$key] ?? 0);
                }
            } finally {
                Tenant::setCurrent(null);
            }
        }

        $this->info('FUP evaluation complete: ' . json_encode($total));

        return self::SUCCESS;
    }
}