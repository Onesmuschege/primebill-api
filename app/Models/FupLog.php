<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class FupLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_account_id', 'bytes_used',
        'triggered_at', 'reset_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'reset_at'     => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }
}
