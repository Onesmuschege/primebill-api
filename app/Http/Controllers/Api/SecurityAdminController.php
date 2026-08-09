<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\UserDevice;

/**
 * SecurityAdminController
 *
 * Domain K — Security: security event log and tracked user devices.
 *
 * NOTE: IP restrictions are intentionally NOT a dedicated model here — they
 * are implemented via the existing `ip.restriction` middleware + settings
 * (see Security\\IpRestrictionTest). No parallel model is introduced.
 */
class SecurityAdminController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'security-events' => [
            'model' => SecurityEvent::class,
            'search' => ['event', 'severity'],
            'rules' => [],
        ],
        'user-devices' => [
            'model' => UserDevice::class,
            'search' => ['device_name', 'platform'],
            'rules' => ['user_id' => 'required|exists:users,id'],
        ],
    ];
}
