<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class WorkOrderChecklist extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'work_order_template_id',
        'title',
        'description',
        'type',
        'input_type',
        'options',
        'is_required',
        'sort_order',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'options' => 'array',
        'metadata' => 'array',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkOrderTemplate::class, 'work_order_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
