<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Drop this trait onto any model with a tenant_id column and every query
 * against it is automatically scoped to the current tenant — no need to
 * remember `where('tenant_id', ...)` on every query site, which is the
 * single easiest way to accidentally leak one tenant's data into another's
 * view.
 *
 * Deliberately NOT applied to User — login must look a user up by email
 * before any tenant is known, so User resolves tenant FROM the user
 * (tenant_id + relation only), never the other way around. Applying this
 * trait to User would break login.
 *
 * Console/queue context: Tenant::current() returns null outside an HTTP
 * request unless something explicitly binds 'currentTenant' first (e.g. a
 * scheduled command processing one tenant at a time should bind it itself
 * before touching tenant-scoped models). The global scope simply doesn't
 * apply when there's no current tenant, so queries fall through to
 * unscoped — be deliberate about that in any command touching these
 * models outside a request.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($tenant = Tenant::current()) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenant->id);
            }
        });

        static::creating(function ($model) {
            if (!$model->tenant_id && $tenant = Tenant::current()) {
                $model->tenant_id = $tenant->id;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Escape hatch for platform-admin/support tooling that legitimately
     * needs to see across all tenants (e.g. a future super-admin panel).
     * Use sparingly and never expose this to tenant-facing code paths.
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }
}