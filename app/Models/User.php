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
        // tenant_id is the tenant the user belongs to. Settable so internal
        // creation (tenant onboarding, platform admin creating staff,
        // tests/factories) can assign it directly. It must never be pulled
        // from $request->all() in a controller — the tenant resolver and
        // ResolveTenant middleware enforce which tenant a request belongs to.
        'tenant_id',
        // Deliberately NOT mass-assignable in the sense of being settable
        // from a request — no controller should ever pull this from
        // $request->all(). It's listed here only so the platform:make-admin
        // command and factories can set it directly via create()/update().
        'is_platform_admin',
        // MFA / 2FA — stored encrypted at rest (see MfaService), set via the
        // dedicated /api/mfa/* endpoints, never from an arbitrary request.
        'mfa_enabled',
        'mfa_secret',
        'mfa_backup_codes',
        'mfa_enabled_at',
        // Per-user allowed-IP allowlist (nullable JSON array), enforced by
        // the IpRestriction middleware on sensitive routes.
        'allowed_ips',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_backup_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_enabled_at' => 'datetime',
            'allowed_ips' => 'array',
        ];
    }

    // Deliberately no BelongsToTenant trait here — see the trait's docblock
    // for why. This is a plain relation only; TenantResolver reads
    // $user->tenant_id directly once auth:sanctum has resolved the user.
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
