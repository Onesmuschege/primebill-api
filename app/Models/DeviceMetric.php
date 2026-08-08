<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class DeviceMetric extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'DeviceMetric';

    protected $fillable = [
        'device_id', 'metric_type', 'value', 'interface',
        'unit', 'recorded_at',
    ];

    protected $casts = [
        'value'       => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Router::class, 'device_id');
    }
}
