<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSettingsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Platform-level operator settings (PrimeBill itself, not tenant ISPs).
 *
 * Only settings in PlatformSettingsService::SCHEMA are accepted — every one of
 * them genuinely drives platform behavior (security suspicion thresholds,
 * platform invoice numbering/terms). No decorative settings exist here.
 */
class PlatformSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(protected PlatformSettingsService $settings) {}

    /**
     * GET /api/platform/settings — grouped settings merged over defaults.
     */
    public function index(): JsonResponse
    {
        return $this->success([
            'groups' => PlatformSettingsService::GROUPS,
            'settings' => $this->settings->all(),
        ]);
    }

    /**
     * PUT /api/platform/settings — bulk update of known keys.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
        ]);

        try {
            $persisted = $this->settings->bulkUpdate($request->array('settings'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), ['settings' => [$e->getMessage()]], 422);
        }

        return $this->success([
            'groups' => PlatformSettingsService::GROUPS,
            'settings' => $this->settings->all(),
            'updated' => $persisted,
        ], 'Platform settings updated successfully');
    }
}