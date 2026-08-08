<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\Tenant;
use App\Services\Olt\OltService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 — Fiber / OLT Management.
 *
 * Polls optical signal (rx/tx) for every ONT on every active tenant's OLTs
 * and flags threshold breaches. The vendor adapter (Huawei/ZTE/FiberHome/
 * VSOL/Mock) performs the actual read; the mock driver simulates readings
 * so the pipeline is testable end-to-end.
 */
class PollOntSignal extends Command
{
    protected $signature = 'fiber:poll-ont-signal {--olt= : Poll a specific OLT ID}';

    protected $description = 'Poll optical signal (rx/tx) for all ONTs and evaluate thresholds';

    public function handle(OltService $oltService): int
    {
        $polled  = 0;
        $failed  = 0;
        $oltOption = $this->option('olt');

        if ($oltOption) {
            $olt = Olt::find($oltOption);

            if (!$olt) {
                $this->error("OLT #{$oltOption} not found.");

                return self::FAILURE;
            }

            return $this->pollOlt($oltService, $olt) ? self::SUCCESS : self::FAILURE;
        }

        $tenants = Tenant::query()->where('status', '!=', 'suspended')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found. Nothing to poll.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            app()->instance('currentTenant', $tenant);

            $olts = Olt::query()->where('status', '!=', 'maintenance')->get();

            foreach ($olts as $olt) {
                if ($this->pollOlt($oltService, $olt)) {
                    $polled++;
                } else {
                    $failed++;
                }
            }
        }

        app()->forgetInstance('currentTenant');

        $this->info("Polled ONT signal across {$tenants->count()} tenant(s) / {$polled} OLT(s); {$failed} failed.");

        return self::SUCCESS;
    }

    protected function pollOlt(OltService $oltService, Olt $olt): bool
    {
        try {
            $result = $oltService->pollAllOntSignals($olt);

            $this->line("Polled {$result['polled']} ONT(s) on {$olt->name} "
                . "({$result['online']} online, {$result['offline']} offline)");

            return true;
        } catch (\Throwable $e) {
            Log::error("PollOntSignal: error polling OLT {$olt->name}: {$e->getMessage()}", [
                'olt_id' => $olt->id,
            ]);
            $this->error("Error polling {$olt->name}: {$e->getMessage()}");

            return false;
        }
    }
}
