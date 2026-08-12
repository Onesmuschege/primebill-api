<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rma extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected $table = 'rmas';

    protected $fillable = [
        'tenant_id',
        'rma_number',
        'inventory_item_id',
        'customer_equipment_id',
        'client_id',
        'supplier_id',
        'work_order_id',
        'type',
        'priority',
        'status',
        'reason',
        'description',
        'notes',
        'tracking_number',
        'expected_return_at',
        'returned_at',
        'completed_at',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'resolved_by',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $casts = [
        'expected_return_at' => 'datetime',
        'returned_at'        => 'datetime',
        'completed_at'       => 'datetime',
        'requested_at'       => 'datetime',
        'approved_at'        => 'datetime',
        'metadata'           => 'array',
    ];

    /** @var string Human-readable alias recorded in the audit (system_logs) trail */
    protected string $auditAlias = 'Rma';

    // ── Catalogue: types / priorities / statuses ─────────────────────────────
    const TYPE_RETURN      = 'return';
    const TYPE_REPLACEMENT = 'replacement';
    const TYPE_REPAIR      = 'repair';

    const PRIORITY_LOW    = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH   = 'high';
    const PRIORITY_URGENT = 'urgent';

    const STATUS_REQUESTED  = 'requested';
    const STATUS_APPROVED   = 'approved';
    const STATUS_REJECTED   = 'rejected';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_CANCELLED  = 'cancelled';

    /**
     * Permitted forward transitions. Terminal states (completed, cancelled,
     * rejected) have no outgoing edges.
     */
    const ALLOWED_TRANSITIONS = [
        self::STATUS_REQUESTED  => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED   => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
    ];

    public static function types(): array       { return [self::TYPE_RETURN, self::TYPE_REPLACEMENT, self::TYPE_REPAIR]; }
    public static function priorities(): array  { return [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_URGENT]; }
    public static function statuses(): array    { return [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_CANCELLED]; }
    public static function terminalStatuses(): array { return [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_REJECTED]; }

    // ── Relations ────────────────────────────────────────────────────────────
    public function inventoryItem()     { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
    public function customerEquipment() { return $this->belongsTo(CustomerEquipment::class, 'customer_equipment_id'); }
    public function client()             { return $this->belongsTo(Client::class); }
    public function supplier()         { return $this->belongsTo(Supplier::class); }
    public function workOrder()        { return $this->belongsTo(WorkOrder::class); }
    public function requestedBy()      { return $this->belongsTo(User::class, 'requested_by'); }
    public function approvedBy()       { return $this->belongsTo(User::class, 'approved_by'); }
    public function resolvedBy()       { return $this->belongsTo(User::class, 'resolved_by'); }
    public function createdBy()        { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy()        { return $this->belongsTo(User::class, 'updated_by'); }
    public function tenant()           { return $this->belongsTo(Tenant::class); }

    // ── Scopes ───────────────────────────────────────────────────────────────
    public function scopeOpen($query)      { return $query->whereNotIn('status', static::terminalStatuses()); }
    public function scopeByStatus($query, string $status) { return $query->where('status', $status); }
}
