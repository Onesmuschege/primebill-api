<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(
        private ApiKeyService $apiKeyService
    ) {}

    /**
     * List API keys for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $keys = $this->apiKeyService->getUserKeys($user);

        // Mask the secret - only show last 4 chars
        $keys->each(function ($key) {
            $key->key_secret = $key->key_secret ? substr($key->key_secret, -4) : null;
        });

        return response()->json($keys);
    }

    /**
     * Create a new API key.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'scopes' => 'nullable|array',
            'expires_at' => 'nullable|date',
        ]);

        $user = $request->user();
        $scopes = $request->input('scopes', []);
        $expiresAt = $request->input('expires_at') ? \Carbon\Carbon::parse($request->input('expires_at')) : null;

        $apiKey = $this->apiKeyService->generateKey($user, $request->input('name'), $scopes, $expiresAt);

        // Return the full secret only on creation
        return response()->json([
            'id' => $apiKey->id,
            'name' => $apiKey->name,
            'key_id' => $apiKey->key_id,
            'key_secret' => decrypt($apiKey->key_secret),
            'scopes' => $apiKey->scopes,
            'expires_at' => $apiKey->expires_at,
            'created_at' => $apiKey->created_at,
        ], 201);
    }

    /**
     * Revoke an API key.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $apiKey = $this->apiKeyService->getUserKeys($user)->find($id);

        if (!$apiKey) {
            return response()->json(['message' => 'API key not found'], 404);
        }

        $this->apiKeyService->revokeKey($apiKey);

        return response()->json(['message' => 'API key revoked successfully']);
    }
}
