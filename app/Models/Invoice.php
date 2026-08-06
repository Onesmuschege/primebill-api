<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class Invoice extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'Invoice';

    protected $fillable = [
        'client_id', 'subscription_id', 'invoice_number', 'amount',
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

    public function subscription()
    {
        return $this->belongsTo(CustomerSubscription::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
