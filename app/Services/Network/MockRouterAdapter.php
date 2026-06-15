<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\Log;

class MockRouterAdapter implements RouterAdapterInterface
{
    public function createUser(array $data): bool
    {
        Log::info('MockRouterAdapter:createUser', $data);

        return true;
    }

    public function deleteUser(string $username): bool
    {
        Log::info('MockRouterAdapter:deleteUser', ['username' => $username]);

        return true;
    }

    public function suspendUser(string $username): bool
    {
        Log::info('MockRouterAdapter:suspendUser', ['username' => $username]);

        return true;
    }

    public function unsuspendUser(string $username): bool
    {
        Log::info('MockRouterAdapter:unsuspendUser', ['username' => $username]);

        return true;
    }

    public function testConnection(): bool
    {
        Log::info('MockRouterAdapter:testConnection');

        return true;
    }
}
