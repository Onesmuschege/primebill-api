<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Session management — list, show, and revoke Sanctum personal access tokens.
 *
 * This gives users visibility into their active sessions (created via login
 * or API key generation) and the ability to remotely revoke them.
 */
class SessionController extends Controller
{
    use ApiResponse;

    /**
     * List active sessions (tokens) for the authenticated user.
     */
public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $currentToken = $user->currentAccessToken();
        $currentId = $currentToken ? $currentToken->id : null;

        $tokens = PersonalAccessToken::where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user))
            ->orderByDesc('last_used_at')
            ->paginate(15)
            ->through(function ($token) use ($currentId) {
                $abilities = $token->abilities ?? [];

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $abilities,
                    'last_used_at' => $token->last_used_at?->toISOString(),
                    'last_used_ip' => $token->last_used_ip, // populated by a middleware if needed
                    'created_at' => $token->created_at->toISOString(),
                    'expires_at' => $token->expires_at?->toISOString(),
                    'is_current' => $currentId !== null && $token->id === $currentId,
                ];
            });

        return $this->success($tokens);
    }

    /**
     * Revoke a specific session (token).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $token = PersonalAccessToken::where('id', $id)
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user))
            ->first();

        if (!$token) {
            return $this->error('Session not found', null, 404);
        }

        // Prevent revoking the current session token
        $currentToken = $request->bearerToken();
        if ($currentToken && $token->token === hash('sha256', $currentToken)) {
            return $this->error('Cannot revoke the current session. Use logout instead.', null, 422);
        }

        $token->delete();

        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'session.revoked',
            'model' => 'PersonalAccessToken',
            'model_id' => $id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->success(null, 'Session revoked successfully');
    }

/**
     * Revoke all sessions except the current one.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PersonalAccessToken::where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user));

        $currentToken = $user->currentAccessToken();

        if ($currentToken) {
            $query->where('id', '!=', $currentToken->id);
        }

        $count = $query->delete();

        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'session.revoked_all',
            'model' => 'PersonalAccessToken',
            'model_id' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => ['count' => $count],
        ]);

        return $this->success(['revoked_count' => $count], 'All other sessions revoked successfully');
    }
}
