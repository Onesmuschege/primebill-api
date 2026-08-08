<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class DashboardWidget extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'dashboard_id',
        'name',
        'code',
        'type',
        'chart_type',
        'data_source',
        'query',
        'options',
        'layout',
        'sort_order',
        'refresh_interval',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'query' => 'array',
        'options' => 'array',
        'layout' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'refresh_interval' => 'integer',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isChart(): bool
    {
        return in_array($this->type, ['chart', 'line', 'bar', 'pie', 'area']);
    }

    public function isMetric(): bool
    {
        return $this->type === 'metric' || $this->type === 'number';
    }
}
