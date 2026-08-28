<?php

namespace App\Services\Radius;

interface RadiusAdapterInterface
{
    public function createUser(array $data): bool;

    public function deleteUser(string $username): bool;

    public function suspendUser(string $username): bool;

    public function unsuspendUser(string $username): bool;

    public function syncUsers(): bool;

    public function syncUsersToAccount(\App\Models\ClientAccount $account): bool;

    /**
     * Push a new rate limit (e.g. after FUP throttling) for a user.
     * Implementations should update the rate-limit attribute for the
     * username on the RADIUS backend.
     */
    public function changeRateLimit(string $username, string $rate): bool;
}
