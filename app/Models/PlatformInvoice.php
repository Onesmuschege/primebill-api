<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PrimeBill invoice to one of its tenant ISPs — i.e. PrimeBill charging the
 * ISP for its own subscription. This is deliberately separate from the
 * tenant-scoped Invoice model, which is what an ISP uses to bill ITS clients.
 *
 * No BelongsToTenant scope on purpose: this table has a plain tenant_id and
 * is only ever queried by platform admins, so every query here returns rows
 * across every tenant without a resolved tenant context.
 */
class PlatformInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'invoice_number',
        'amount',
        'tax_amount',
        'total',
        'status',
        'billing_period',
        'issue_date',
        'due_date',
        'paid_at',
        'reference',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlatformInvoiceItem::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
