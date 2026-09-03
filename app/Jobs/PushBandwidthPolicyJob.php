<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\EffectiveRateResolver;
use App\Services\Radius\RadiusControlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PushBandwidthPolicyJob — authoritative "make this service carry the
 * correct network policy right now" operation (Core ISP Gate — Sections 13/22/23).
 *
 * Used by plan change/upgrade/downgrade so that the RADIUS + live-session
 * policy converges on the NEW plan instead of leaving a stale speed in
 * force (DB=30 Mbps, RADIUS=10 Mbps is a FAILED state).
 *
 * The effective rate is resolved through EffectiveRateResolver so an active
 * FUP throttle is honoured even across a plan change (Section 23).
 *
 * Runs asynchronously so the local transaction is never held open while
 * talking to the network (Section 60).
 */
class PushBandwidthPolicyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $accountId,
        public ?int $tenantId = null,
        public ?string $reason = null
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
    }

    public function handle(
        RadiusControlService $radiusControl,
        EffectiveRateResolver $rateResolver
    ): void {
        $this->establishTenantContext();

        try {
            $account = ClientAccount::with('plan')->find($this->accountId);

            if (!$account) {
                Log::warning('PushBandwidthPolicyJob: account not found', ['id' => $this->accountId]);

                return;
            }

            if (!$account->plan) {
                Log::warning('PushBandwidthPolicyJob: account has no plan', ['id' => $this->accountId]);

                return;
            }

            // The authoritative, FUP-aware desired rate for this service.
            $rate = $rateResolver->effectiveRate($account);

            $outcome = $radiusControl->applyRateLimit(
                $account,
                $rate,
                $this->reason ?? 'plan/policy sync'
            );

            Log::info('PushBandwidthPolicyJob: policy pushed', [
                'account_id'  => $account->id,
                'username'    => $account->username,
                'rate'        => $rate,
                'radius_ok'   => $outcome['radius_ok'],
                'live_applied'=> $outcome['live_applied'],
            ]);
        } finally {
            Tenant::setCurrent(null);
        }
    }

    protected function establishTenantContext(): void
    {
        if ($this->tenantId) {
            $tenant = Tenant::find($this->tenantId);

            if ($tenant) {
                Tenant::setCurrent($tenant);
            }
        }
    }
}
