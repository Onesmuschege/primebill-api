<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class FiberConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_account_id',
        'ont_id',
        'pon_port_id',
        'olt_id',
        'fiber_route_id',
        'fiber_splitter_id',
        'distribution_point_id',
        'status',
        'connection_type',
        'port_number',
        'serial_number',
        'mac_address',
        'technical_details',
        'installation_date',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'technical_details' => 'array',
        'metadata' => 'array',
        'installation_date' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function ont(): BelongsTo
    {
        return $this->belongsTo(Ont::class);
    }

    public function ponPort(): BelongsTo
    {
        return $this->belongsTo(PonPort::class);
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function fiberRoute(): BelongsTo
    {
        return $this->belongsTo(FiberRoute::class);
    }

    public function fiberSplitter(): BelongsTo
    {
        return $this->belongsTo(FiberSplitter::class);
    }

    public function distributionPoint(): BelongsTo
    {
        return $this->belongsTo(DistributionPoint::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
