<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class LoginHistory extends Model
{
    use BelongsToTenant;

    // The migration creates the singular table name explicitly.
    protected $table = 'login_history';

    protected $fillable = [
        'user_id',
        'email',
        'ip_address',
        'user_agent',
        'device',
        'location',
        'success',
        'failure_reason',
        'logged_in_at',
        'logged_out_at',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
