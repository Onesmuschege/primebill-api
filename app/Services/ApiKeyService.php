<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class ApiKeyService
{
    /**
     * Generate a new API key pair.
     */
    public function generateKey(User $user, string $name, ?array $scopes = null, ?\DateTime $expiresAt = null): ApiKey
    {
        $keyId = Str::random(32);
        $keySecret = Str::random(64);

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $name,
            'key_id' => $keyId,
            'key_hash' => Hash::make($keySecret),
            'key_secret' => encrypt($keySecret),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return $apiKey;
    }

    /**
     * Validate an API key.
     */
    public function validateKey(string $keyId, string $keySecret): ?ApiKey
    {
        $apiKey = ApiKey::where('key_id', $keyId)
            ->where('revoked', false)
            ->first();

        if (!$apiKey || !$apiKey->isValid()) {
            return null;
        }

        if (!Hash::check($keySecret, $apiKey->key_hash)) {
            return null;
        }

        return $apiKey;
    }

    /**
     * Revoke an API key.
     */
    public function revokeKey(ApiKey $apiKey): void
    {
        $apiKey->update(['revoked' => true]);
    }

    /**
     * Get all API keys for a user.
     */
    public function getUserKeys(User $user)
    {
        return ApiKey::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
    }
}
