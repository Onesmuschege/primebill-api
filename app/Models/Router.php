<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Traits\Encryptable;

class Router extends Model
{
    use HasFactory, BelongsToTenant, Encryptable;

    protected $fillable = [
        'name', 'ip_address', 'username', 'password',
        'port', 'type', 'location', 'status', 'last_seen',
        'device_type', 'model', 'vendor', 'snmp_community',
        'snmp_port', 'snmp_version', 'location_lat', 'location_lng',
        'tenant_id',
        // Network Core — NAS/RADIUS fields
        'radius_ip', 'radius_auth_port', 'radius_acct_port',
        'coa_port', 'radius_secret_encrypted', 'nas_identifier',
        'nas_type', 'routeros_version', 'capabilities',
    ];

    protected $hidden = ['password', 'radius_secret_encrypted'];

    protected $casts = [
        'last_seen' => 'datetime',
        'snmp_port' => 'integer',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
        'radius_auth_port' => 'integer',
        'radius_acct_port' => 'integer',
        'coa_port' => 'integer',
        'capabilities' => 'array',
    ];

    /**
     * Router credentials are encrypted at rest (see Encryptable trait).
     * The column must be TEXT to hold the encrypted payload.
     */
    protected $encryptable = ['password', 'snmp_community', 'radius_secret_encrypted'];

    public function traffic()
    {
        return $this->hasMany(NetworkTraffic::class);
    }

    public function metrics()
    {
        return $this->hasMany(DeviceMetric::class, 'device_id');
    }

    public function alerts()
    {
        return $this->hasMany(NetworkAlert::class, 'device_id');
    }

    public function openAlerts()
    {
        return $this->alerts()->where('status', 'open');
    }

    public function linksA()
    {
        return $this->hasMany(NetworkLink::class, 'device_a_id');
    }

    public function linksB()
    {
        return $this->hasMany(NetworkLink::class, 'device_b_id');
    }

    // ─── Network Core — NAS relationships ─────────────────────────────

    public function clientAccounts()
    {
        return $this->hasMany(ClientAccount::class, 'nas_id');
    }

    public function devices()
    {
        return $this->hasMany(Device::class, 'nas_id');
    }

    public function networkEvents()
    {
        return $this->hasMany(NetworkEvent::class, 'nas_id');
    }

    public function radiusControlLogs()
    {
        return $this->hasMany(RadiusControlLog::class, 'nas_id');
    }

    public function supportsCapability(string $capability): bool
    {
        $caps = $this->capabilities ?? [];

        return in_array($capability, $caps, true);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
