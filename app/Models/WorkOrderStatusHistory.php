<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class WorkOrderStatusHistory extends Model
{
    use BelongsToTenant;

    /**
     * The migration creates the table in the singular form
     * (create_work_order_status_history_table) — name it explicitly to
     * match, since Eloquent would otherwise assume the plural.
     */
    protected $table = 'work_order_status_history';

    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'from_status',
        'to_status',
        'reason',
        'metadata',
        'changed_by',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
