<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class SmsLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_id', 'phone', 'message',
        'status', 'gateway_response', 'gateway',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
