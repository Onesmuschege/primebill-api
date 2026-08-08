<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class ServiceProfile extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'ServiceProfile';

    protected $fillable = [
        'name', 'description',
        'download_speed', 'upload_speed', 'burst_down', 'burst_up',
        'session_timeout', 'idle_timeout', 'simultaneous_sessions',
        'data_limit_bytes', 'fup_download_speed', 'fup_upload_speed',
        'custom_radius_attributes', 'service_type', 'is_active',
    ];

    protected $casts = [
        'custom_radius_attributes' => 'array',
        'is_active'                => 'boolean',
    ];

    public function accounts()
    {
        return $this->hasMany(ClientAccount::class);
    }

    /**
     * Generate RADIUS reply attributes from this profile.
     */
    public function toRadiusAttributes(): array
    {
        $attributes = [];

        // Bandwidth policy
        if ($this->download_speed || $this->upload_speed) {
            $down = $this->download_speed ?? 0;
            $up   = $this->upload_speed ?? 0;
            $attributes['Mikrotik-Rate-Limit'] = "{$up}k/{$down}k";
        }

        // Session policy
        if ($this->session_timeout) {
            $attributes['Session-Timeout'] = (string) $this->session_timeout;
        }

        if ($this->idle_timeout) {
            $attributes['Idle-Timeout'] = (string) $this->idle_timeout;
        }

        if ($this->simultaneous_sessions > 1) {
            $attributes['Simultaneous-Use'] = (string) $this->simultaneous_sessions;
        }

        // Custom attributes override defaults
        foreach (($this->custom_radius_attributes ?? []) as $attr => $value) {
            $attributes[$attr] = $value;
        }

        return $attributes;
    }

    /**
     * Generate RADIUS check attributes from this profile.
     */
    public function toRadiusCheckAttributes(): array
    {
        $checks = [];

        if ($this->simultaneous_sessions > 1) {
            $checks['Simultaneous-Use'] = (string) $this->simultaneous_sessions;
        }

        foreach (($this->custom_radius_attributes ?? []) as $attr => $value) {
            if (str_starts_with($attr, 'check:')) {
                $checks[substr($attr, 6)] = $value;
            }
        }

        return $checks;
    }
}
