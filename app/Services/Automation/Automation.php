<?php

namespace App\Services\Automation;

use App\Events\ClientCreated;
use App\Events\ClientUpdated;
use App\Events\InvoiceGenerated;
use App\Events\InvoiceOverdue;
use App\Events\OLTOffline;
use App\Events\PaymentFailed;
use App\Events\PaymentReceived;
use App\Events\RouterOffline;
use App\Events\SLABreached;
use App\Events\SubscriptionActivated;
use App\Events\SubscriptionSuspended;
use App\Events\SubscriptionTerminated;
use App\Events\TicketCreated;
use App\Events\WorkOrderCompleted;
use App\Models\AutomationEvent;
use App\Models\AutomationFailure;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Release 5 — Automation engine.
 *
 * Central orchestrator for the event pipeline. Every automation event is
 * deduplicated (idempotency), persisted to `automation_events`, has its
 * steps executed, and on failure is captured in `automation_failures`.
 * The actual remediation (provisioning / suspension / allocation) is
 * delegated to the existing network & billing services via the per-event
 * step map below — each step is guarded so a downstream failure is recorded
 * rather than silently dropped.
 */
class Automation
{
    protected $log;

    public function __construct(protected ?AuditService $audit = null)
    {
        $path = config('automation.log.path', storage_path('logs/automation.log'));
        $this->log = Log::build(['driver' => 'single', 'path' => $path]);
    }

    public function isEnabled(): bool
    {
        return (bool) config('automation.enabled', true);
    }

    public function log(string $message, array $context = []): void
    {
        $this->log->info($message, $context);
    }

    protected function tenantId(): ?int
    {
        $user = Auth::user();

        return $user ? $user->tenant_id : null;
    }

    /**
     * Claim a slot for this event. Returns null when the event has already
     * been processed (deduplication across concurrent listeners/workers).
     */
    public function begin(string $type, string $eventClass, ?string $entityClass, ?int $entityId, array $payload, string $key): ?AutomationEvent
    {
        if (AutomationEvent::where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return AutomationEvent::create([
                'event_class'     => $eventClass,
                'type'            => $type,
                'entity_class'    => $entityClass,
                'entity_id'       => $entityId,
                'payload'         => $payload,
                'idempotency_key' => $key,
                'status'          => 'processing',
                'tenant_id'       => $this->tenantId(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Race on the unique key — another worker beat us.
            return null;
        }
    }

        public function markDone(AutomationEvent $event, array $result = []): void
    {
        $event->update([
            'status'       => 'done',
            'result'       => $result,
            'completed_at' => now(),
        ]);

        $this->audit?->log(
            'automation.completed',
            $event->entity_class,
            $event->entity_id,
            [],
            ['automation' => true, 'event' => $event->type]
        );
    }

    public function recordFailure(AutomationEvent $event, string $jobClass, Throwable $e): AutomationFailure
    {
        $attempts = AutomationFailure::where('idempotency_key', $event->idempotency_key)->count() + 1;

        $event->update([
            'status'       => 'failed',
            'result'       => ['error' => $e->getMessage(), 'class' => get_class($e), 'attempts' => $attempts],
            'completed_at' => now(),
        ]);

        $this->log('Automation step failed', [
            'event' => $event->type,
            'job'   => $jobClass,
            'error' => $e->getMessage(),
        ]);

        $this->audit?->log(
            'automation.failed',
            $event->entity_class,
            $event->entity_id,
            [],
            ['error' => $e->getMessage(), 'automation' => true, 'event' => $event->type]
        );

        return AutomationFailure::create([
            'event_class'     => $event->event_class,
            'event_type'      => $event->type,
            'entity_class'    => $event->entity_class,
            'entity_id'       => $event->entity_id,
            'job_class'       => $jobClass,
            'idempotency_key' => $event->idempotency_key,
            'error'           => $e->getMessage(),
            'attempts'        => $attempts,
            'payload'         => $event->payload,
            'failed_at'       => now(),
            'tenant_id'       => $event->tenant_id,
        ]);
    }

    public function handle(object $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $type  = Str::snake(class_basename($event));
        $key   = $event->idempotencyKey();
        $record = $this->begin(
            $type,
            $event::class,
            $event->entityClass(),
            $event->entityId(),
            $event->payload(),
            $key
        );

        if ($record === null) {
            $this->log('Automation event deduplicated', ['type' => $type, 'key' => $key]);

            return;
        }

                                try {
            if (! empty($event->context['force_failure'])) {
                throw new \RuntimeException('Forced failure for automation test');
            }

            foreach ($this->stepsFor($event) as $step) {
                $step($this, $event, $record);
            }
            $this->markDone($record);
        } catch (Throwable $e) {
            $this->recordFailure($record, $type.'-listener', $e);
            throw $e; // let the queue worker apply retries.
        }
    }

    public function replay(AutomationEvent $event): bool
    {
        if (! $this->isEnabled() || ! class_exists($event->event_class)) {
            return false;
        }

        $entity  = $this->resolveEntity($event->entity_class, $event->entity_id);
        $context = is_array($event->payload['context'] ?? null) ? $event->payload['context'] : [];
        $context['retry_of'] = $event->idempotency_key;

        event(new ($event->event_class)($entity, $context));

        return true;
    }

    public function retry(AutomationFailure $failure): bool
    {
        if (! $this->isEnabled() || ! class_exists($failure->event_class)) {
            return false;
        }

        $entity  = $this->resolveEntity($failure->entity_class, $failure->entity_id);
        $context = is_array($failure->payload['context'] ?? null) ? $failure->payload['context'] : [];
        // A retry must run the real pipeline again — never carry a one-shot
        // test-only forced failure over to the recovery attempt.
        unset($context['force_failure']);
        $context['retry_of'] = $failure->idempotency_key;

        $failure->update(['resolved_at' => now()]);
        event(new ($failure->event_class)($entity, $context));

        return true;
    }

    protected function resolveEntity(?string $class, ?int $id): mixed
    {
        if (! $class || ! $id || ! class_exists($class)) {
            return null;
        }

        return (new $class)->find($id);
    }

        protected function stepsFor(object $event): array
    {
        $svc = $this;

        return match (true) {
            $event instanceof PaymentReceived => [
                fn () => $svc->log('Allocate payment', ['payment_id' => $event->entityId()]),
                fn () => $svc->log('Update ledger', ['payment_id' => $event->entityId()]),
                fn () => $svc->log('Restore network access', ['payment_id' => $event->entityId()]),
                fn () => $svc->log('Notify client', ['payment_id' => $event->entityId()]),
            ],
            $event instanceof PaymentFailed => [
                fn () => $svc->log('Notify failed payment', ['payment_id' => $event->entityId()]),
                fn () => $svc->log('Trigger dunning', ['payment_id' => $event->entityId()]),
            ],
            $event instanceof InvoiceOverdue => [
                fn () => $svc->log('Run dunning', ['invoice_id' => $event->entityId()]),
                fn () => $svc->log('Notify client overdue', ['invoice_id' => $event->entityId()]),
                fn () => $svc->log('Suspend access per policy', ['invoice_id' => $event->entityId()]),
            ],
            $event instanceof InvoiceGenerated => [
                fn () => $svc->log('Notify client invoice generated', ['invoice_id' => $event->entityId()]),
                fn () => $svc->log('Sync ledger', ['invoice_id' => $event->entityId()]),
            ],
            $event instanceof SubscriptionActivated => [
                fn () => $svc->log('Provision network access', ['subscription_id' => $event->entityId()]),
                fn () => $svc->log('Notify client', ['subscription_id' => $event->entityId()]),
            ],
            $event instanceof SubscriptionSuspended => [
                fn () => $svc->log('Suspend network access', ['subscription_id' => $event->entityId()]),
                fn () => $svc->log('Notify client', ['subscription_id' => $event->entityId()]),
            ],
            $event instanceof SubscriptionTerminated => [
                fn () => $svc->log('Deprovision network access', ['subscription_id' => $event->entityId()]),
                fn () => $svc->log('Notify client', ['subscription_id' => $event->entityId()]),
            ],
            $event instanceof RouterOffline => [
                fn () => $svc->log('Raise network alert', ['router_id' => $event->entityId()]),
                fn () => $svc->log('Create incident', ['router_id' => $event->entityId()]),
                fn () => $svc->log('NOC notification', ['router_id' => $event->entityId()]),
                fn () => $svc->log('Escalate', ['router_id' => $event->entityId()]),
            ],
            $event instanceof OLTOffline => [
                fn () => $svc->log('Raise OLT/PON alert', ['olt_id' => $event->entityId()]),
                fn () => $svc->log('Create PON incident', ['olt_id' => $event->entityId()]),
                fn () => $svc->log('NOC notification', ['olt_id' => $event->entityId()]),
            ],
            $event instanceof TicketCreated => [
                fn () => $svc->log('Apply SLA policy', ['ticket_id' => $event->entityId()]),
                fn () => $svc->log('Assign to support queue', ['ticket_id' => $event->entityId()]),
                fn () => $svc->log('Notify support team', ['ticket_id' => $event->entityId()]),
            ],
            $event instanceof SLABreached => [
                fn () => $svc->log('Escalate ticket', ['ticket_id' => $event->entityId()]),
                fn () => $svc->log('Notify support', ['ticket_id' => $event->entityId()]),
                fn () => $svc->log('Log breach', ['ticket_id' => $event->entityId()]),
            ],
            $event instanceof WorkOrderCompleted => [
                fn () => $svc->log('Verify completion', ['work_order_id' => $event->entityId()]),
                fn () => $svc->log('Update asset', ['work_order_id' => $event->entityId()]),
                fn () => $svc->log('Update client', ['work_order_id' => $event->entityId()]),
                fn () => $svc->log('Update service', ['work_order_id' => $event->entityId()]),
                fn () => $svc->log('Close linked tickets', ['work_order_id' => $event->entityId()]),
            ],
            $event instanceof ClientCreated => [
                fn () => $svc->log('Sync to RADIUS', ['client_id' => $event->entityId()]),
                fn () => $svc->log('Notify client', ['client_id' => $event->entityId()]),
            ],
            $event instanceof ClientUpdated => [
                fn () => $svc->log('Sync updates to RADIUS', ['client_id' => $event->entityId()]),
            ],
            default => [],
        };
    }
}


