<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * BelongsToTenant Trait
 *
 * Automatically scopes all queries to the current tenant and injects
 * tenant_id on model creation. This is the primary isolation mechanism
 * for the multi-tenant SaaS architecture.
 *
 * Usage:
 *   class Client extends Model {
 *       use BelongsToTenant;
 *       protected $fillable = ['tenant_id', 'name', 'phone', ...];
 *   }
 */
trait BelongsToTenant
{
    /**
     * Boot the trait and register the global scope + model event listeners.
     */
    protected static function bootBelongsToTenant(): void
    {
        // Global scope: automatically add WHERE tenant_id = ? to every query
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (App::bound('tenant') && !empty(App::make('tenant')->id)) {
                $builder->where(static::getTable() . '.tenant_id', App::make('tenant')->id);
            }
        });

        // Auto-inject tenant_id on creation (if not already set)
        static::creating(function (Model $model) {
            if (App::bound('tenant') && !$model->tenant_id) {
                $model->tenant_id = App::make('tenant')->id;
            }
        });
    }

    /**
     * Scope query to a specific tenant (bypasses global scope temporarily).
     *
     * @param Builder $query
     * @param int $tenantId
     * @return Builder
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope('tenant')
                     ->where(static::getTable() . '.tenant_id', $tenantId);
    }

    /**
     * Check if this model belongs to the current tenant.
     *
     * @return bool
     */
    public function belongsToCurrentTenant(): bool
    {
        if (!App::bound('tenant')) {
            return false;
        }

        return $this->tenant_id === App::make('tenant')->id;
    }
}
