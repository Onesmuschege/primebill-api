<?php

namespace App\Services\Radius;

use App\Models\ClientAccount;
use App\Services\Network\NetworkEventService;

class RadiusControlService
{
    public function __construct(
        protected NetworkEventService $networkEventService,
        protected RadiusCoaClient $coaClient
    ) {}

    public function suspend(ClientAccount $account): bool
    {
        $adapter = app(RadiusAdapterInterface::class);

        return $adapter->suspendUser($account->username);
    }

    public function activate(ClientAccount $account): bool
    {
        $adapter = app(RadiusAdapterInterface::class);

        return $adapter->unsuspendUser($account->username);
    }

    public function applyPolicy(ClientAccount $account, array $policy): bool
    {
        // Build the effective Mikrotik-Rate-Limit from the requested policy.
        $down = max(1, (int) ($policy['download_speed'] ?? $account->plan?->speed_down ?? 1024));
        $up   = max(1, (int) ($policy['upload_speed'] ?? $account->plan?->speed_up ?? 512));
        $rate = "{$up}k/{$down}k";

        $outcome = $this->applyRateLimit($account, $rate, 'bandwidth policy update');

        return $outcome['radius_ok'];
    }

    /**
     * Push a new rate limit for the account to the RADIUS backend
     * (e.g. after FUP throttling or on FUP reset).
     */
    public function changeRateLimit(ClientAccount $account, string $rate): bool
    {
        return $this->applyRateLimit($account, $rate, 'rate-limit change')['radius_ok'];
    }

    /**
     * Authoritative rate-limit application (Core ISP Gate — Sections 13/21):
     *
     *   1. Write the rate to the RADIUS backend (radreply) — the policy the
     *      NEXT authentication receives. This is the durable, authoritative
     *      state change.
     *   2. Attempt a live CoA on the customer's active session so the change
     *      takes effect WITHOUT a reconnect. When the NAS NAKs (rate-limit
     *      commonly requires a disconnect) the CoA client falls back to a
     *      Disconnect-Request so re-auth applies the new rate.
     *
     * The outcome of BOTH steps is returned and recorded as a NetworkEvent so
     * the operator can see exactly what happened on the network — a silent
     * DB-only "success" would be misleading (Sections 15/55).
     *
     * @return array{
     *   radius_ok: bool,
     *   coa: array{success:bool,method:?string,response_code:?int,error:?string,reason:?string},
     *   live_applied: bool,
     *   requires_reconnect: bool
     * }
     */
    public function applyRateLimit(ClientAccount $account, string $rate, string $context = 'rate-limit change'): array
    {
        $adapter = app(RadiusAdapterInterface::class);
        $radiusOk = $adapter->changeRateLimit($account->username, $rate);

        if (!$radiusOk) {
            $this->networkEventService->record(
                'POLICY_UPDATE_FAILED',
                "Failed to write rate-limit {$rate} for {$account->username}",
                ['rate_limit' => $rate, 'context' => $context],
                'warning',
                $account->client_id,
                $account->id,
                $account->nas_id,
                null,
                'system'
            );

            return [
                'radius_ok'          => false,
                'coa'                => (new CoaResult(false, null, null, 'RADIUS backend write failed', 'radius_write_failed'))->toArray(),
                'live_applied'       => false,
                'requires_reconnect' => true,
            ];
        }

        $account->update(['rate_limit_policy' => $rate]);

        // Live enforcement on the customer's active session (CoA, with a
        // Disconnect fallback when the NAS cannot CoA the attribute).
        $coa = $this->coaClient->changeRate($account, $rate);

        $this->networkEventService->record(
            'POLICY_UPDATED',
            "Rate-limit policy updated to {$rate} for {$account->username}",
            [
                'rate_limit'         => $rate,
                'context'            => $context,
                'coa'                => $coa->toArray(),
                'live_applied'       => $coa->success,
                'requires_reconnect' => !$coa->success,
            ],
            $coa->success ? 'info' : 'warning',
            $account->client_id,
            $account->id,
            $account->nas_id,
            null,
            'system'
        );

        return [
            'radius_ok'          => true,
            'coa'                => $coa->toArray(),
            'live_applied'       => $coa->success,
            'requires_reconnect' => !$coa->success,
        ];
    }

    /**
     * Live CoA disconnect for the account's active session. Terminates the
     * session WITHOUT touching credentials (Section 20).
     */
    public function disconnectSession(ClientAccount $account): array
    {
        $result = $this->coaClient->disconnect($account);

        $this->networkEventService->record(
            $result->success ? 'SESSION_DISCONNECTED' : 'SESSION_DISCONNECT_FAILED',
            $result->success
                ? "CoA disconnect issued for {$account->username}"
                : "CoA disconnect failed for {$account->username}: {$result->error}",
            ['coa' => $result->toArray()],
            $result->success ? 'info' : 'warning',
            $account->client_id,
            $account->id,
            $account->nas_id,
            null,
            'system'
        );

        return $result->toArray();
    }
}
