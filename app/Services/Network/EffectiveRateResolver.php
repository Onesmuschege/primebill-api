<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\FupLog;
use App\Models\Plan;

/**
 * EffectiveRateResolver — THE single authority for the rate-limit a service
 * should carry on the network right now (Core ISP Gate — Section 23).
 *
 * Separates BASE PLAN from FUP POLICY:
 *
 *   effectiveRate(account) =
 *       FUP actively triggered ? throttled rate : base plan rate
 *
 * Every provisioning / re-provisioning / sync path MUST resolve rates through
 * this resolver instead of blindly reading plan speeds — otherwise a generic
 * reconciliation (e.g. `radius:sync-users`, or a re-provision after restore)
 * would overwrite an active FUP throttle with the base rate while the FUP
 * override is still in force. Only an explicit FUP reset removes the
 * override (FupService::reset) — reconciliation must never do it implicitly.
 */
class EffectiveRateResolver
{
    /**
     * The rate the service should be enforcing right now.
     */
    public function effectiveRate(ClientAccount $account): string
    {
        $plan = $account->plan;

        if (!$plan) {
            return '512k/1024k';
        }

        if ($this->isFupActive($account)) {
            // Prefer the stored policy (what the FUP trigger actually wrote);
            // fall back to the plan's configured FUP speeds.
            return $account->rate_limit_policy ?: $this->throttledRate($plan);
        }

        return $this->baseRate($plan);
    }

    /**
     * True when a FUP throttle is currently in force for this account.
     */
    public function isFupActive(ClientAccount $account): bool
    {
        return FupLog::where('client_account_id', $account->id)
            ->whereNotNull('triggered_at')
            ->exists();
    }

    /**
     * The plan's base (unthrottled) rate.
     */
    public function baseRate(Plan $plan): string
    {
        $down = $plan->speed_down ? max(1, (int) $plan->speed_down) : 1024;
        $up   = $plan->speed_up ? max(1, (int) $plan->speed_up) : 512;

        return "{$up}k/{$down}k";
    }

    /**
     * The plan's FUP-throttled rate.
     */
    public function throttledRate(Plan $plan): string
    {
        $down = $plan->fup_speed_down ?? max(1, (int) floor(($plan->speed_down ?? 1024) / 10));
        $up   = $plan->fup_speed_up ?? max(1, (int) floor(($plan->speed_up ?? 512) / 10));

        return "{$up}k/{$down}k";
    }
}
