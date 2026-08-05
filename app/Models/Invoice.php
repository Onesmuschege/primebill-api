<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToTenant;

class Invoice extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'client_id', 'invoice_number', 'amount',
        'tax', 'total', 'status', 'due_date',
        'paid_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_at'  => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
