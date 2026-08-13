<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\AutomationFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Release 5 — scheduled housekeeping for the automation engine.
 *
 * Runs daily. Prunes resolved failures older than the retention window and
 * cancels events stuck in "processing" that have not been touched in days.
 */
class AutomationPruneFailures extends Command
{
    protected $signature = 'automation:prune-failures';
    protected $description = 'Prune resolved automation failures and stale processing events.';

    public function handle(): int
    {
        $pruned = AutomationFailure::whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays(90))
            ->delete();

        $stale = AutomationEvent::where('status', 'processing')
            ->where('updated_at', '<', now()->subDays(7))
            ->update(['status' => 'cancelled']);

        Log::info('Automation prune', [
            'resolved_pruned' => (int) $pruned,
            'stale_cancelled' => (int) $stale,
            'command'         => 'automation:prune-failures',
        ]);

        $this->info("Pruned {$pruned} resolved failures, cancelled {$stale} stale events.");

        return self::SUCCESS;
    }
}
