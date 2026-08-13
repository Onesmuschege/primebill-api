<?php

namespace App\Events;

use Illuminate\Support\Str;

/**
 * Shared helpers for every PrimeBill automation event.
 *
 * Events carry the originating entity (a Model) plus a free-form context
 * bag. They are responsible for deriving their own idempotency key, entity
 * reference and serializable payload — the pipeline never inspects event
 * internals directly.
 */
trait InteractsWithAutomation
{
    public function entityClass(): ?string
    {
        $entity = $this->entity ?? null;

        return ($entity instanceof \Illuminate\Database\Eloquent\Model)
            ? get_class($entity)
            : null;
    }

    public function entityType(): ?string
    {
        $class = $this->entityClass();

        return $class ? class_basename($class) : null;
    }

    public function entityId(): ?int
    {
        $entity = $this->entity ?? null;

        if ($entity instanceof \Illuminate\Database\Eloquent\Model && isset($entity->id)) {
            return (int) $entity->id;
        }

        return $this->context['entity_id'] ?? null;
    }

    public function key(): ?string
    {
        $entity = $this->entity;

        return $this->context['key'] ?? $this->payload();
    }

    public function payload(): array
    {
        $out = ['context' => $this->context ?? []];

        $entity = $this->entity ?? null;
        if ($entity instanceof \Illuminate\Database\Eloquent\Model && method_exists($entity, 'toArray')) {
            try {
                $out['entity'] = $entity->toArray();
            } catch (\Throwable) {
                // ignore serialization failures — keep the pipeline alive
            }
        }

        return $out;
    }

    /**
     * Stable idempotency key. Same event + entity + context never re-runs.
     */
    public function idempotencyKey(): string
    {
        $id = $this->entityId();

        return 'autom:' . sha1(
            static::class . '|' . ($id ?? '') . '|' . json_encode($this->context ?? [])
        );
    }
}
