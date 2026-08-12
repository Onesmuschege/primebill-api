<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Support\SlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EvaluateSla extends Command
{
    protected $signature = 'sla:evaluate {--tenant-id= : Scope evaluation to a single tenant}';

    protected $description = 'Evaluate ticket SLA targets, mark breaches and auto-escalate';

    public function handle(SlaService $sla): int
    {
        if ($tenantId = $this->option('tenant-id')) {
            Tenant::setCurrent(Tenant::find((int) $tenantId));
        }

        $result = $sla->evaluate();

        $this->info(json_encode($result));
        Log::info('SLA evaluation complete', $result);

        return self::SUCCESS;
    }
}