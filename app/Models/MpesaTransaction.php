<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class MpesaTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_id',
        'invoice_id',
        'phone',
        'amount',
        'account_reference',
        'merchant_request_id',
        'checkout_request_id',
        'mpesa_receipt_number',
        'result_code',
        'result_desc',
        'status',
        'raw_request',
        'raw_callback',
        'idempotency_key',
        'idempotency_created_at',
    ];

    protected $casts = [
        'raw_request' => 'array',
        'raw_callback' => 'array',
        'idempotency_created_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
