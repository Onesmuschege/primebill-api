<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class DistributionPoint extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'DistributionPoint';

    protected $fillable = [
        'name', 'type', 'location', 'location_lat', 'location_lng',
        'status', 'notes', 'tenant_id',
    ];

    protected $casts = [
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];
}
