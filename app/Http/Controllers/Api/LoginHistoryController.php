<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoginHistoryService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginHistoryController extends Controller
{
    public function __construct(
        private LoginHistoryService $loginHistoryService
    ) {}

    /**
     * Get login history for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $history = $this->loginHistoryService->getUserHistory($user);

        return response()->json($history);
    }

    /**
     * Get security events (admin only).
     */
    public function securityEvents(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only platform admins can view security events
        if (!$user->hasRole('Super Admin') && !$user->is_platform_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $events = $this->loginHistoryService->getSecurityEvents();

        return response()->json($events);
    }
}
