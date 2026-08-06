<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'Address';

    protected $fillable = [
        'addressable_type', 'addressable_id',
        'type', 'label', 'address_line1', 'address_line2',
        'town', 'county', 'postal_code', 'country',
        'gps_lat', 'gps_lng', 'directions',
        'is_verified', 'verified_at', 'is_primary',
    ];

    protected $casts = [
        'gps_lat' => 'decimal:7',
        'gps_lng' => 'decimal:7',
        'is_verified' => 'boolean',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public const TYPES = ['installation', 'billing', 'home', 'business', 'other'];

    public function addressable()
    {
        return $this->morphTo();
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->town,
            $this->county,
            $this->postal_code,
            $this->country,
        ]);
        return implode(', ', $parts);
    }
}
