<?php

namespace App\Traits;

use App\Services\Audit\AuditService;

/**
 * LogsAudit Trait
 *
 * Automatically logs model events to the audit system.
 * Use this trait on models that need automatic audit logging.
 *
 * Usage:
 *   class Client extends Model
 *   {
 *       use LogsAudit;
 *
 *       protected string $auditAlias = 'Client';
 *   }
 */
trait LogsAudit
{
    /**
     * Boot the trait
     */
    public static function bootLogsAudit(): void
    {
        static::created(function ($model) {
            $model->logAudit('created', [], $model->toArray());
        });

        static::updated(function ($model) {
            $model->logAudit('updated', $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function ($model) {
            $model->logAudit('deleted', $model->toArray(), []);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->logAudit('restored', [], $model->toArray());
            });
        }
    }

    /**
     * Log an audit event for this model
     */
    protected function logAudit(string $event, array $oldValues = [], array $newValues = []): void
    {
        if (!app()->bound(AuditService::class)) {
            return;
        }

        $auditService = app(AuditService::class);
        $modelName = $this->getAuditAlias();

        $auditService->log(
            action: "{$modelName}.{$event}",
            model: $modelName,
            modelId: $this->getKey(),
            oldValues: $oldValues,
            newValues: $newValues
        );
    }

    /**
     * Get the alias for this model (used in audit logs)
     */
    protected function getAuditAlias(): string
    {
        return $this->auditAlias ?? class_basename($this);
    }
}
