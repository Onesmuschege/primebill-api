<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\FupLog;

/**
 * Fair Usage Policy (FUP) service.
 * Monitors data usage against plan FUP thresholds.
 */
class FupService
{
    public function __construct(
        protected RadiusControlService $radiusControl,
        protected NetworkEventService $networkEvents
    ) {}

    public function evaluate(ClientAccount $account): void
    {
        $plan = $account->plan;
        if (!$plan || !$plan->fup_limit) return;

        $limitBytes = $plan->fup_limit * 1024 * 1024;
        $bytesUsed = $account->radiusSessions()
            ->where('session_start', '>=', $account->activated_at ?? now()->subDays(30))
            ->sum('bytes_in')
            + $account->radiusSessions()
            ->where('session_start', '>=', $account->activated_at ?? now()->subDays(30))
            ->sum('bytes_out');

        $fupLog = FupLog::firstOrNew(
            ['client_account_id' => $account->id],
            ['bytes_used' => 0, 'reset_at' => now()]
        );
        $fupLog->bytes_used = $bytesUsed;

        if ($bytesUsed >= $limitBytes && !$fupLog->triggered_at) {
            $fupLog->triggered_at = now();
            $fupLog->save();

            $throttledDown = $plan->fup_speed_down ?? max(1, $plan->speed_down / 10);
            $throttledUp = $plan->fup_speed_up ?? max(1, $plan->speed_up / 10);
            $rate = "{$throttledUp}k/{$throttledDown}k";

            $account->update(['rate_limit_policy' => $rate]);
            $this->radiusControl->changeRateLimit($account, $rate);

            $this->networkEvents->fupTriggered($account->id, [
                'bytes_used' => $bytesUsed,
                'limit_bytes' => $limitBytes,
                'throttled_rate' => $rate,
            ]);
        } else {
            $fupLog->save();
        }
    }

    public function reset(ClientAccount $account): void
    {
        $fupLog = FupLog::firstOrNew(['client_account_id' => $account->id]);
        $fupLog->bytes_used = 0;
        $fupLog->triggered_at = null;
        $fupLog->reset_at = now();
        $fupLog->save();

        $plan = $account->plan;
        if ($plan) {
            $originalRate = "{$plan->speed_up}k/{$plan->speed_down}k";
            $account->update(['rate_limit_policy' => $originalRate]);
            $this->radiusControl->changeRateLimit($account, $originalRate);

            $this->networkEvents->record(
                'FUP_RESET',
                "FUP reset for {$account->username}",
                ['original_rate' => $originalRate],
                'info',
                $account->client_id,
                $account->id,
                $account->nas_id,
                null,
                'system'
            );
        }
    }

    public function evaluateAll(): array
    {
        $evaluated = 0;
        $triggered = 0;

        ClientAccount::with('plan')
            ->whereHas('plan', fn ($q) => $q->whereNotNull('fup_limit'))
            ->where('service_state', ClientAccount::STATE_ACTIVE)
            ->chunkById(100, function ($accounts) use (&$evaluated, &$triggered) {
                foreach ($accounts as $account) {
                    $before = $account->rate_limit_policy;
                    $this->evaluate($account);
                    $evaluated++;
                    if ($account->fresh()->rate_limit_policy !== $before) {
                        $triggered++;
                    }
                }
            });

        return ['evaluated' => $evaluated, 'triggered' => $triggered];
    }
}
