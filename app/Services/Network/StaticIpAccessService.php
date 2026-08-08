<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\NetworkEvent;
use App\Models\RadiusControlLog;
use App\Services\Radius\RadiusAdapterInterface;

class StaticIpAccessService implements AccessMethodInterface
{
    public function __construct(
        protected RouterAdapterInterface $routerAdapter,
        protected RadiusAdapterInterface $radiusAdapter
    ) {}

    public function provision(ClientAccount $account, string $plainPassword): bool
    {
        // Static IP services still need RADIUS for authorization + accounting
        $radiusOk = $this->radiusAdapter->createUser([
            'username'   => $account->username,
            'password'   => $plainPassword,
            'group'      => $account->plan?->name ?? 'default',
            'rate_limit' => $this->buildRateLimit($account),
        ]);

        $routerOk = $this->routerAdapter->createUser([
            'username'  => $account->username,
            'password'  => $plainPassword,
            'profile'   => $account->plan?->name ?? 'default',
            'plan_type' => 'pppoe', // Static IP via PPPoE uses the PPPoE provisioning path
            'router_id' => $account->nas_id ?? $account->plan?->router_id,
        ]);

        NetworkEvent::create([
            'event_type'        => 'STATIC_IP_PROVISIONED',
            'severity'          => $routerOk && $radiusOk ? 'info' : 'warning',
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Static IP service {$account->username} provisioned (IP: {$account->ip_address})",
            'context'           => ['router_ok' => $routerOk, 'radius_ok' => $radiusOk],
            'source'            => 'system',
        ]);

        return $routerOk && $radiusOk;
    }

    public function suspend(ClientAccount $account): bool
    {
        $routerOk = $this->routerAdapter->suspendUser($account->username);
        $radiusOk = $this->radiusAdapter->suspendUser($account->username);

        $this->disconnectActiveSessions($account);

        NetworkEvent::create([
            'event_type'        => 'STATIC_IP_SUSPENDED',
            'severity'          => 'warning',
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Static IP service {$account->username} suspended",
            'context'           => ['router_ok' => $routerOk, 'radius_ok' => $radiusOk, 'ip' => $account->ip_address],
            'source'            => 'system',
        ]);

        return $routerOk && $radiusOk;
    }

    public function restore(ClientAccount $account): bool
    {
        $routerOk = $this->routerAdapter->unsuspendUser($account->username);
        $radiusOk = $this->radiusAdapter->unsuspendUser($account->username);

        NetworkEvent::create([
            'event_type'        => 'STATIC_IP_RESTORED',
            'severity'          => 'info',
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Static IP service {$account->username} restored",
            'context'           => ['router_ok' => $routerOk, 'radius_ok' => $radiusOk],
            'source'            => 'system',
        ]);

        return $routerOk && $radiusOk;
    }

    public function deprovision(ClientAccount $account): bool
    {
        $routerOk = $this->routerAdapter->deleteUser($account->username);
        $radiusOk = $this->radiusAdapter->deleteUser($account->username);

        NetworkEvent::create([
            'event_type'        => 'STATIC_IP_DEPROVISIONED',
            'severity'          => 'info',
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Static IP service {$account->username} deprovisioned",
            'context'           => ['router_ok' => $routerOk, 'radius_ok' => $radiusOk],
            'source'            => 'system',
        ]);

        return $routerOk && $radiusOk;
    }

    public function applyBandwidthPolicy(ClientAccount $account, array $policy): bool
    {
        $down = $policy['download_speed'] ?? 1024;
        $up   = $policy['upload_speed'] ?? 512;
        $rate = "{$up}k/{$down}k";

        $radiusOk = $this->radiusAdapter->createUser([
            'username'   => $account->username,
            'password'   => $account->password,
            'group'      => $account->plan?->name ?? 'default',
            'rate_limit' => $rate,
        ]);

        RadiusControlLog::create([
            'action'            => 'change_rate_limit',
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'username'          => $account->username,
            'status'            => $radiusOk ? 'completed' : 'failed',
            'request'           => ['rate_limit' => $rate],
            'attempts'          => 1,
            'created_at'        => now(),
            'completed_at'      => $radiusOk ? now() : null,
        ]);

        $account->update(['rate_limit_policy' => $rate]);

        return $radiusOk;
    }

    public function disconnectSession(ClientAccount $account, ?string $sessionId = null): bool
    {
        $activeSession = $account->radiusSessions()
            ->where('status', 'active')
            ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))
            ->latest('session_start')
            ->first();

        if (!$activeSession) {
            return true;
        }

        RadiusControlLog::create([
            'action'            => 'disconnect_session',
            'radius_session_id' => $activeSession->id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'username'          => $account->username,
            'session_id'        => $activeSession->session_id,
            'status'            => 'sent',
            'request'           => ['session_id' => $activeSession->session_id],
            'attempts'          => 1,
            'created_at'        => now(),
        ]);

        $activeSession->update(['status' => 'stale']);

        return true;
    }

    protected function disconnectActiveSessions(ClientAccount $account): void
    {
        $account->radiusSessions()
            ->where('status', 'active')
            ->get()
            ->each(fn ($session) => $this->disconnectSession($account, $session->session_id));
    }

    protected function buildRateLimit(ClientAccount $account): string
    {
        $plan = $account->plan;
        $down = $plan->speed_down ?? 1024;
        $up   = $plan->speed_up ?? 512;

        return "{$up}k/{$down}k";
    }
}
