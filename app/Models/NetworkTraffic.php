<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class NetworkTraffic extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'router_id', 'tx_bytes',
        'rx_bytes', 'interface', 'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
