<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class NotificationPreference extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'notifiable_type',
        'notifiable_id',
        'email_enabled',
        'sms_enabled',
        'whatsapp_enabled',
        'push_enabled',
        'in_app_enabled',
        'quiet_hours',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'email_enabled' => 'array',
        'sms_enabled' => 'array',
        'whatsapp_enabled' => 'array',
        'push_enabled' => 'array',
        'in_app_enabled' => 'array',
        'quiet_hours' => 'array',
        'metadata' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function notifiable()
    {
        return $this->morphTo();
    }
}
