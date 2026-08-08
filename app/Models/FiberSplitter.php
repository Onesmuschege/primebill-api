<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class FiberSplitter extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'FiberSplitter';

    protected $fillable = [
        'name', 'split_ratio', 'location', 'location_lat', 'location_lng',
        'status', 'tenant_id',
    ];

    protected $casts = [
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];
}
