<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class FiberRoute extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'FiberRoute';

    protected $fillable = [
        'name', 'source', 'destination', 'length_km',
        'cable_type', 'status', 'notes', 'tenant_id',
    ];

    protected $casts = [
        'length_km' => 'decimal:3',
    ];
}
