<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'Client';

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone',
        'id_number', 'address', 'county', 'town',
        'gps_lat', 'gps_lng', 'status', 'created_by',
        'referral_code', 'referred_by', 'referral_count', 'referral_bonus',
        'loyalty_points_balance',
    ];

    public function accounts()
    {
        return $this->hasMany(ClientAccount::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
