<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Services\Radius\RadiusAdapterInterface;
use App\Services\Radius\RadiusControlService;

class PppoeAccessService implements AccessMethodInterface
{
    public function __construct(
        protected RouterAdapterInterface $router,
        protected RadiusAdapterInterface $radius
    ) {}

    public function provision(ClientAccount $account, string $plainPassword): bool
    {
        $routerOk = $this->router->createUser([
            'username'  => $account->username,
            'password'  => $plainPassword,
            'profile'   => $account->plan->name ?? 'default',
            'plan_type' => $account->plan->type ?? 'pppoe',
            'router_id' => $account->plan->router_id ?? null,
        ]);

        $radiusOk = $this->radius->createUser([
            'username'   => $account->username,
            'password'   => $plainPassword,
            'group'      => $account->plan->name ?? 'default',
            'rate_limit' => $this->buildRateLimit($account),
        ]);

        return $routerOk && $radiusOk;
    }

    public function suspend(ClientAccount $account): bool
    {
        return $this->router->suspendUser($account->username)
            && $this->radius->suspendUser($account->username);
    }

    public function activate(ClientAccount $account): bool
    {
        return $this->router->unsuspendUser($account->username)
            && $this->radius->unsuspendUser($account->username);
    }

    public function disconnectSession(ClientAccount $account, ?string $sessionId = null): bool
    {
        return $this->router->deleteUser($account->username);
    }

    public function restore(ClientAccount $account): bool
    {
        return $this->router->unsuspendUser($account->username)
            && $this->radius->unsuspendUser($account->username);
    }

    public function deprovision(ClientAccount $account): bool
    {
        return $this->router->deleteUser($account->username)
            && $this->radius->deleteUser($account->username);
    }

    public function applyBandwidthPolicy(ClientAccount $account, array $policy): bool
    {
        // CoA not fully implemented yet.
        return true;
    }

    protected function buildRateLimit(ClientAccount $account): string
    {
        $plan = $account->plan;
        if (!$plan) return '512k/1024k';

        $down = $plan->speed_down ? max(1, (int) $plan->speed_down) : 1024;
        $up = $plan->speed_up ? max(1, (int) $plan->speed_up) : 512;

        return "{$up}k/{$down}k";
    }
}
