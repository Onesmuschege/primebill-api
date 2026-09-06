<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PrimeBill-operator (platform) settings — no tenant scope.
 *
 * These are global constants that drive the platform console: security
 * thresholds, billing defaults, etc. See PlatformSettingsService for the
 * typed schema + defaults.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
    ];
}