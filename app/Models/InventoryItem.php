<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class InventoryItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name', 'category', 'quantity', 'unit_cost',
        'serial_number', 'assigned_to_client_id',
        'status', 'low_stock_alert',
    ];

    public function assignedClient()
    {
        return $this->belongsTo(Client::class, 'assigned_to_client_id');
    }
}
