<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class LedgerEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_id',
        'invoice_id',
        'payment_id',
        'entry_type',
        'amount',
        'currency',
        'description',
        'meta',
        'recorded_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
