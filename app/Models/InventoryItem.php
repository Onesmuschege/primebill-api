<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class InventoryItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name', 'category', 'quantity', 'unit_cost',
        'serial_number', 'assigned_to_client_id',
        'status', 'low_stock_alert', 'warehouse_id',
    ];

    public function assignedClient()
    {
        return $this->belongsTo(Client::class, 'assigned_to_client_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(InventoryItemHistory::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InventoryAssignment::class);
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class);
    }
}
