<?php

namespace App\Models;

use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Traits\LogsAudit;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens, LogsAudit;

    protected string $auditAlias = 'User';

    protected $fillable = [
        'name',
        'email',
        'password',
        // Deliberately NOT mass-assignable in the sense of being settable
        // from a request — no controller should ever pull this from
        // $request->all(). It's listed here only so the platform:make-admin
        // command and factories can set it directly via create()/update().
        'is_platform_admin',
    ];

    // Deliberately no BelongsToTenant trait here — see the trait's docblock
    // for why. This is a plain relation only; TenantResolver reads
    // $user->tenant_id directly once auth:sanctum has resolved the user.
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}
