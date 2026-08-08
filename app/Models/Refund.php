<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class Refund extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'payment_id',
        'invoice_id',
        'refund_number',
        'amount',
        'currency',
        'method',
        'reference',
        'status',
        'reason',
        'reference_uuid',
        'reversed_by',
        'reversed_at',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
