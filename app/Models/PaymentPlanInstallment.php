<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class PaymentPlanInstallment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'payment_plan_id',
        'sequence',
        'amount',
        'paid_amount',
        'status',
        'due_date',
        'paid_at',
        'payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'sequence' => 'integer',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
