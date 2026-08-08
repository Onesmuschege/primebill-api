<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class EquipmentHistory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_equipment_id',
        'action',
        'field',
        'old_value',
        'new_value',
        'notes',
        'actor_id',
        'client_id',
        'work_order_id',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function customerEquipment(): BelongsTo
    {
        return $this->belongsTo(CustomerEquipment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
