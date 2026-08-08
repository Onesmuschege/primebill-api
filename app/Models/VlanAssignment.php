<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class VlanAssignment extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'VlanAssignment';

    protected $fillable = [
        'vlan_id', 'assignable_type', 'assignable_id',
        'is_trunk', 'trunk_ports', 'description',
    ];

    protected $casts = [
        'is_trunk' => 'boolean',
    ];

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function assignable()
    {
        return $this->morphTo();
    }
}
