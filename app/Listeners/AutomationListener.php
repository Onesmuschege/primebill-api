<?php

namespace App\Listeners;

use App\Services\Automation\Automation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Release 5 — single generic queued listener wired to every automation event.
 *
 * It delegates to the Automation service so all retry/idempotency/failure
 * semantics live in one place. Registered per-event in AutomationServiceProvider.
 */
class AutomationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries;
    public int $timeout;
    public $backoff;
    public string $queue = 'automation';

    public function __construct(protected Automation $automation)
    {
        $this->tries   = (int) config('automation.retry.tries', 3);
        $this->timeout = (int) config('automation.retry.timeout', 300);
        $this->backoff = config('automation.retry.backoff', [15, 45, 120]);
    }

    public function handle(object $event): void
    {
        $this->automation->handle($event);
    }

    /**
     * Backup failure path for the async queue worker (the handle() path also
     * records failures synchronously so tests with QUEUE_CONNECTION=sync see them).
     */
    public function failed(object $event, Throwable $exception): void
    {
        Log::build(['driver' => 'single', 'path' => config('automation.log.path', storage_path('logs/automation.log'))])
            ->error('Automation listener failed permanently', [
                'listener' => static::class,
                'event'    => get_class($event),
                'error'    => $exception->getMessage(),
            ]);
    }
}
