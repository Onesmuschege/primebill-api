<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'client_id',
        'invoice_id',
        'amount',
        'method',
        'reference',
        'mpesa_code',
        'idempotency_key',   // Unique dedup key — MpesaReceiptNumber for STK/C2B,
                             // caller-supplied UUID for cash/bank.
        'status',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}