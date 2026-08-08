<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class PonPort extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'PonPort';

    protected $fillable = [
        'olt_id', 'name', 'technology', 'status',
        'max_onts', 'registered_onts', 'tenant_id',
    ];

    protected $casts = [
        'max_onts' => 'integer',
        'registered_onts' => 'integer',
    ];

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function onts()
    {
        return $this->hasMany(Ont::class);
    }
}
