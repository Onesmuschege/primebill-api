<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RadiusAttribute extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'radius_profile_id',
        'name',
        'vendor',
        'type',
        'value',
        'opcode',
        'priority',
        'is_encrypted',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    public function radiusProfile(): BelongsTo
    {
        return $this->belongsTo(RadiusProfile::class);
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
