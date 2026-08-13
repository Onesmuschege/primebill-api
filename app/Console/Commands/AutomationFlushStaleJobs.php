<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\AutomationFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Release 5 — scheduled stale-job reconciliation.
 *
 * Runs hourly. Any automation_event still "processing" past the staleness
 * threshold is treated as a stuck job: it is marked failed and captured in
 * `automation_failures` so it is visible in the console and retryable.
 */
class AutomationFlushStaleJobs extends Command
{
    protected $signature = 'automation:flush-stale-jobs {--stale-minutes=60 : Minutes after which a processing event is considered stuck}';
    protected $description = 'Flag automation events stuck in processing as failed.';

    public function handle(): int
    {
        $minutes = (int) $this->option('stale-minutes');

        $stale = AutomationEvent::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        $count = 0;
        foreach ($stale as $event) {
            AutomationFailure::create([
                'event_class'     => $event->event_class,
                'event_type'      => $event->type,
                'entity_class'    => $event->entity_class,
                'entity_id'       => $event->entity_id,
                'job_class'       => 'StaleJobDetector',
                'idempotency_key' => $event->idempotency_key,
                'error'           => "Event stuck in processing for >{$minutes} minutes",
                'attempts'        => 1,
                'payload'         => $event->payload,
                'failed_at'       => now(),
                'tenant_id'       => $event->tenant_id,
            ]);

            $event->update(['status' => 'failed', 'completed_at' => now()]);
            $count++;
        }

                Log::info('Automation stale jobs flushed', ['count' => $count, 'stale_minutes' => $minutes]);

        $this->info("Flushed {$count} stale automation events.");

        return self::SUCCESS;
    }
}
