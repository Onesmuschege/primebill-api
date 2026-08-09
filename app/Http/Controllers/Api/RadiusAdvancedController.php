<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\RadiusAttribute;
use App\Models\RadiusCoaRequest;
use App\Models\RadiusDisconnectRequest;
use App\Models\RadiusProfile;

/**
 * RadiusAdvancedController
 *
 * Domain D — Advanced RADIUS: reusable profiles, dynamic attributes and
 * Change-of-Authorization / disconnect request tracking.
 */
class RadiusAdvancedController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'radius-profiles' => [
            'model' => RadiusProfile::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'radius-attributes' => [
            'model' => RadiusAttribute::class,
            'rules' => [
                'radius_profile_id' => 'required|exists:radius_profiles,id',
                'name' => 'required|string|max:255',
            ],
        ],
        'radius-coa-requests' => [
            'model' => RadiusCoaRequest::class,
            'rules' => ['radius_session_id' => 'nullable|exists:radius_sessions,id'],
        ],
        'radius-disconnect-requests' => [
            'model' => RadiusDisconnectRequest::class,
            'rules' => ['radius_session_id' => 'nullable|exists:radius_sessions,id'],
        ],
    ];
}
