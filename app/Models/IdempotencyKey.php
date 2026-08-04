<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class IdempotencyKey extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'scope',
        'idempotency_key',
        'status',
        'response_payload',
    ];

    protected $casts = [
        'response_payload' => 'array',
    ];
}
