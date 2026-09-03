<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ClientAccount extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'client_id', 'plan_id', 'username', 'password',
        'ip_address', 'mac_address', 'type', 'status',
        'expiry_date', 'activated_at',
        // Network Core — Phase A
        'access_method', 'nas_id', 'service_state',
        'provisioned_at', 'suspended_at', 'restored_at', 'terminated_at',
        // DEPRECATED: never written by production code. Kept for schema
        // compatibility only — entitlement truth is computed, see isEntitled().
        'entitled_until', 'is_entitled', 'rate_limit_policy',
        'service_profile_id',
        // Lifecycle — suspension ownership (SL2)
        'suspension_type', 'suspended_by',
    ];

    protected $casts = [
        'expiry_date'    => 'datetime',
        'activated_at'   => 'datetime',
        'provisioned_at' => 'datetime',
        'suspended_at'   => 'datetime',
        'restored_at'    => 'datetime',
        'terminated_at'  => 'datetime',
        'entitled_until' => 'datetime',
        'is_entitled'    => 'boolean',
        'suspended_by'   => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

public function radiusSessions()
    {
        return $this->hasMany(RadiusSession::class, 'client_account_id');
    }

    public function onts()
    {
        return $this->hasMany(Ont::class);
    }

    // ─── Network Core relationships ───────────────────────────────────

    public function nas()
    {
        return $this->belongsTo(Router::class, 'nas_id');
    }

    public function serviceProfile()
    {
        return $this->belongsTo(ServiceProfile::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class, 'client_account_id');
    }

    public function networkEvents()
    {
        return $this->hasMany(NetworkEvent::class, 'client_account_id');
    }

    public function radiusControlLogs()
    {
        return $this->hasMany(RadiusControlLog::class, 'client_account_id');
    }

    public function ipAllocations()
    {
        return $this->hasMany(IpAllocation::class, 'client_account_id');
    }

    // ─── Service lifecycle ────────────────────────────────────────────

    public const STATE_PENDING      = 'PENDING';
    public const STATE_PROVISIONING = 'PROVISIONING';
    public const STATE_ACTIVE       = 'ACTIVE';
    public const STATE_PAST_DUE     = 'PAST_DUE';
    public const STATE_GRACE_PERIOD = 'GRACE_PERIOD';
    public const STATE_SUSPENDED    = 'SUSPENDED';
    public const STATE_TERMINATED   = 'TERMINATED';

    // Suspension ownership (SL2) — why a service is suspended.
    public const SUSPENSION_BILLING = 'billing';
    public const SUSPENSION_ADMIN   = 'admin';

    public const ACCESS_PPPOE   = 'pppoe';
    public const ACCESS_HOTSPOT = 'hotspot';
    public const ACCESS_STATIC  = 'static_ip';
    public const ACCESS_DHCP    = 'dhcp';

    public function isActive(): bool
    {
        return $this->service_state === self::STATE_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->service_state === self::STATE_SUSPENDED;
    }

    /**
     * SL2 — whether this service is under an explicit administrative hold.
     * Billing reconciliation must never auto-restore such a service.
     */
    public function hasAdministrativeHold(): bool
    {
        return $this->service_state === self::STATE_SUSPENDED
            && $this->suspension_type === self::SUSPENSION_ADMIN;
    }

    /**
     * Truthful entitlement: the service is active AND the owning client is
     * currently entitled (active client profile, no overdue debt).
     *
     * Note: the persisted `is_entitled` column is deprecated dead
     * infrastructure (it has no production writers) and is deliberately
     * ignored here so API responses never report fabricated values.
     */
    public function isEntitled(): bool
    {
        if ($this->service_state !== self::STATE_ACTIVE) {
            return false;
        }

        return app(\App\Services\Network\ServiceLifecycleService::class)->isEntitled($this);
    }

    public function transitionTo(string $newState, ?string $reason = null): bool
    {
        $allowed = $this->allowedTransitions();

        if (!in_array($newState, $allowed, true)) {
            return false;
        }

        $oldState = $this->service_state;

        $this->service_state = $newState;

        $now = now();

        // Compatibility synchronization (Track A): `status` is a legacy
        // column that must never silently diverge from `service_state`.
        if ($newState === self::STATE_ACTIVE) {
            $this->restored_at = $now;
            $this->status = 'active';
            $this->suspension_type = null;
            $this->suspended_by = null;
        } elseif ($newState === self::STATE_SUSPENDED) {
            $this->suspended_at = $now;
            $this->status = 'suspended';
        } elseif ($newState === self::STATE_PROVISIONING) {
            $this->provisioned_at = $now;
        } elseif ($newState === self::STATE_TERMINATED) {
            $this->terminated_at = $now;
            $this->status = 'inactive';
        }

        $this->save();

        NetworkEvent::create([
            'tenant_id'         => $this->tenant_id,
            'event_type'        => 'SERVICE_STATE_CHANGED',
            'severity'          => 'info',
            'client_id'         => $this->client_id,
            'client_account_id' => $this->id,
            'nas_id'            => $this->nas_id,
            'message'           => "Service {$this->username} transitioned from {$oldState} to {$newState}",
            'context'           => [
                'from'   => $oldState,
                'to'     => $newState,
                'reason' => $reason,
            ],
            'source' => 'system',
        ]);

        return true;
    }

    public function allowedTransitions(): array
    {
        return match ($this->service_state) {
            self::STATE_PENDING      => [self::STATE_PROVISIONING, self::STATE_TERMINATED],
            self::STATE_PROVISIONING => [self::STATE_ACTIVE, self::STATE_SUSPENDED, self::STATE_TERMINATED],
            self::STATE_ACTIVE       => [self::STATE_PAST_DUE, self::STATE_SUSPENDED, self::STATE_TERMINATED],
            self::STATE_PAST_DUE     => [self::STATE_GRACE_PERIOD, self::STATE_ACTIVE, self::STATE_SUSPENDED],
            self::STATE_GRACE_PERIOD => [self::STATE_SUSPENDED, self::STATE_ACTIVE, self::STATE_PAST_DUE],
            self::STATE_SUSPENDED    => [self::STATE_ACTIVE, self::STATE_TERMINATED],
            self::STATE_TERMINATED   => [],
            default                  => [],
        };
    }
}
