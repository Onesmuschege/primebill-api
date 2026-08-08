<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Dashboard extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'description',
        'layout',
        'filters',
        'is_default',
        'is_public',
        'sort_order',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'layout' => 'array',
        'filters' => 'array',
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function isPublic(): bool
    {
        return $this->is_public;
    }
}
