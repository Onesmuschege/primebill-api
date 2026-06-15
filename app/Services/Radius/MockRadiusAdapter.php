<?php

namespace App\Services\Radius;

use Illuminate\Support\Facades\Log;

class MockRadiusAdapter implements RadiusAdapterInterface
{
    public function createUser(array $data): bool
    {
        Log::info('MockRadiusAdapter:createUser', $data);

        return true;
    }

    public function deleteUser(string $username): bool
    {
        Log::info('MockRadiusAdapter:deleteUser', ['username' => $username]);

        return true;
    }

    public function suspendUser(string $username): bool
    {
        Log::info('MockRadiusAdapter:suspendUser', ['username' => $username]);

        return true;
    }

    public function unsuspendUser(string $username): bool
    {
        Log::info('MockRadiusAdapter:unsuspendUser', ['username' => $username]);

        return true;
    }

    public function syncUsers(): bool
    {
        Log::info('MockRadiusAdapter:syncUsers');

        return true;
    }
}
