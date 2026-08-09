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
        'discount', 'subtotal', 'tax', 'total', 'status', 'due_date',
        'paid_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_at'  => 'datetime',
        'amount'   => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax'      => 'decimal:2',
        'total'    => 'decimal:2',
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

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function taxLines()
    {
        return $this->hasMany(InvoiceTaxLine::class);
    }

    public function discountLines()
    {
        return $this->hasMany(InvoiceDiscountLine::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function debitNotes()
    {
        return $this->hasMany(DebitNote::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function paymentPlans()
    {
        return $this->hasMany(PaymentPlan::class);
    }

    public function getBalanceAttribute(): float
    {
        $paid = (float) $this->payments()
            ->where('status', 'completed')
            ->sum('amount');

        $refunded = (float) $this->refunds()
            ->where('status', 'completed')
            ->sum('amount');

        return round((float) $this->total - $paid + $refunded, 2);
    }
}
