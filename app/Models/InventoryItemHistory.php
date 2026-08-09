<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class InventoryItemHistory extends Model
{
    use BelongsToTenant;

    /**
     * The migration stores history in a singular table name, which differs
     * from Eloquent's default pluralised `inventory_item_histories`.
     */
    protected $table = 'inventory_item_history';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'action',
        'field',
        'old_value',
        'new_value',
        'notes',
        'actor_id',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
