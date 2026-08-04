<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ClientAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_id', 'plan_id', 'username', 'password',
        'ip_address', 'mac_address', 'type', 'status',
        'expiry_date', 'activated_at',
    ];

    protected $casts = [
        'expiry_date'  => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function radiusSessions()
    {
        return $this->hasMany(RadiusSession::class, 'client_account_id');
    }
}
