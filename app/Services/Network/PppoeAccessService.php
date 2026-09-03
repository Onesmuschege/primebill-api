<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Services\Radius\RadiusAdapterInterface;
use App\Services\Radius\RadiusControlService;

class PppoeAccessService implements AccessMethodInterface
{
    public function __construct(
        protected RouterAdapterInterface $router,
        protected RadiusAdapterInterface $radius,
        protected EffectiveRateResolver $rateResolver
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
        $disabled = $this->router->suspendUser($account->username)
            && $this->radius->suspendUser($account->username);

        // Hard disconnect (Core ISP Gate — Section 19, Option A): disabling
        // future authentication does not drop the customer's live session.
        // Force the active PPPoE session down so a suspended customer is
        // genuinely offline, not merely unable to reconnect.
        if ($disabled) {
            $this->router->disconnectSession($account->username);
        }

        return $disabled;
    }

    public function activate(ClientAccount $account): bool
    {
        return $this->router->unsuspendUser($account->username)
            && $this->radius->unsuspendUser($account->username);
    }

    public function disconnectSession(ClientAccount $account, ?string $sessionId = null): bool
    {
        // Terminate the live session ONLY. This must never delete the
        // credential — a disconnect is not a deprovisioning (Section 20).
        return $this->router->disconnectSession($account->username);
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
        $rate = $this->buildRateLimitFromPolicy($account, $policy);

        // Write the effective rate limit to the RADIUS backend. The next
        // authentication (and any CoA where supported) picks it up. This is
        // the truthful device-side outcome PrimeBill can guarantee without a
        // mandatory session drop.
        return $this->radius->changeRateLimit($account->username, $rate);
    }

    protected function buildRateLimitFromPolicy(ClientAccount $account, array $policy): string
    {
        $down = max(1, (int) ($policy['download_speed'] ?? $account->plan?->speed_down ?? 1024));
        $up   = max(1, (int) ($policy['upload_speed'] ?? $account->plan?->speed_up ?? 512));

        return "{$up}k/{$down}k";
    }

    protected function buildRateLimit(ClientAccount $account): string
    {
        // Section 23 — provisioning honours an active FUP throttle via the
        // single effective-rate authority.
        return $this->rateResolver->effectiveRate($account);
    }
}
