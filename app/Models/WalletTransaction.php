<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class WalletTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'wallet_id',
        'client_id',
        'type',
        'amount',
        'balance_after',
        'reference',
        'description',
        'meta',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'meta' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
