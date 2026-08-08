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
        'direction',
        'account_type',
        'amount',
        'currency',
        'description',
        'meta',
        'counter_entry_id',
        'reference',
        'recorded_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function counterEntry()
    {
        return $this->belongsTo(self::class, 'counter_entry_id');
    }
}
