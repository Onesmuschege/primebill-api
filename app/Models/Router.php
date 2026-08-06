<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Router extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'name', 'ip_address', 'username', 'password',
        'port', 'type', 'location', 'status', 'last_seen',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'last_seen' => 'datetime',
    ];

    public function traffic()
    {
        return $this->hasMany(NetworkTraffic::class);
    }
}
