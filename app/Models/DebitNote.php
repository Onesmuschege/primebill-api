<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class DebitNote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'invoice_id',
        'debit_note_number',
        'amount',
        'currency',
        'status',
        'reason',
        'notes',
        'reference',
        'reversed_by',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
