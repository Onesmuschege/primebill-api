<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class MikroTikRouterAdapter implements RouterAdapterInterface
{
    public function __construct(
        protected MikroTikService $mikrotik
    ) {}

    public function createUser(array $data): bool
    {
        $router = $this->resolveRouter($data['router_id'] ?? null);

        if (!$router || !$this->mikrotik->connect($router)) {
            Log::warning('MikroTikRouterAdapter: failed to connect for createUser', $data);

            return false;
        }

        $username = $data['username'];
        $password = $data['password'];
        $profile  = $data['profile'] ?? config('network.default_ppp_profile');
        $type     = $data['plan_type'] ?? 'pppoe';

        if ($type === 'hotspot') {
            $profile = $data['profile'] ?? config('network.default_hotspot_profile');

            return $this->mikrotik->addHotspotUser($username, $password, $profile);
        }

        return $this->mikrotik->addPPPoEUser($username, $password, $profile);
    }

    public function deleteUser(string $username): bool
    {
        $router = $this->resolveRouterForUsername($username);

        if (!$router || !$this->mikrotik->connect($router)) {
            return false;
        }

        return $this->mikrotik->removePPPoEUser($username);
    }

    public function suspendUser(string $username): bool
    {
        $router = $this->resolveRouterForUsername($username);

        if (!$router || !$this->mikrotik->connect($router)) {
            return false;
        }

        return $this->mikrotik->disablePPPoEUser($username);
    }

    public function unsuspendUser(string $username): bool
    {
        $router = $this->resolveRouterForUsername($username);

        if (!$router || !$this->mikrotik->connect($router)) {
            return false;
        }

        return $this->mikrotik->enablePPPoEUser($username);
    }

    public function testConnection(): bool
    {
        $router = $this->resolveRouter(null);

        if (!$router || !$this->mikrotik->connect($router)) {
            return false;
        }

        return $this->mikrotik->testConnection();
    }

    protected function resolveRouter(?int $routerId): ?Router
    {
        if ($routerId) {
            return Router::find($routerId);
        }

        return Router::where('status', 'online')->first() ?? Router::first();
    }

    protected function resolveRouterForUsername(string $username): ?Router
    {
        $account = \App\Models\ClientAccount::with('plan')
            ->where('username', $username)
            ->first();

        return $this->resolveRouter($account?->plan?->router_id);
    }
}
