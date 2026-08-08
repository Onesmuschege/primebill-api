<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class UsageBillingRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_account_id',
        'invoice_id',
        'billing_period',
        'bytes_used',
        'bytes_included',
        'bytes_overage',
        'rate_per_gb',
        'overage_amount',
        'status',
        'meta',
    ];

    protected $casts = [
        'bytes_used' => 'integer',
        'bytes_included' => 'integer',
        'bytes_overage' => 'integer',
        'rate_per_gb' => 'decimal:2',
        'overage_amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
