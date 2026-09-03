<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\NetworkEvent;
use Illuminate\Support\Facades\Log;

/**
 * SL1 — Authoritative service lifecycle service.
 *
 * Every major service state change (activate / suspend / administrative
 * suspension / billing suspension / reconciliation) flows through this
 * class. Controllers, jobs, billing services and scheduled commands may
 * decide THAT a lifecycle action is required, but the actual transition,
 * its network consequences and its audit event must be performed here so
 * that `status`, `service_state` and the suspension ownership can never
 * silently diverge.
 */
class ServiceLifecycleService
{
    public const SUSPENSION_BILLING = ClientAccount::SUSPENSION_BILLING;
    public const SUSPENSION_ADMIN   = ClientAccount::SUSPENSION_ADMIN;

    public function __construct(
        protected AccessMethodManager $accessMethods
    ) {}

    /**
     * Suspend a service.
     *
     * @param ClientAccount $account  target account
     * @param string|null   $reason   human-readable reason (audited)
     * @param string        $type     why: SUSPENSION_BILLING or SUSPENSION_ADMIN (SL2)
     * @param int|null      $userId   acting operator for administrative suspensions
     *
     * @return bool true if a new suspension was applied now, false when the
     *              service was already fully suspended (idempotent no-op —
     *              no duplicate network operations or audit events).
     */
    public function suspend(ClientAccount $account, ?string $reason = null, string $type = self::SUSPENSION_BILLING, ?int $userId = null): bool
    {
        // Idempotency: already fully suspended (both fields agree).
        if ($account->service_state === ClientAccount::STATE_SUSPENDED
            && $account->status === 'suspended') {
            // Classify a legacy/unclassified suspension, or upgrade a billing
            // suspension to an explicit administrative hold. Never redo the
            // network work.
            if ($account->suspension_type === null
                || ($type === self::SUSPENSION_ADMIN && $account->suspension_type !== self::SUSPENSION_ADMIN)) {
                $account->forceFill([
                    'suspension_type' => $type,
                    'suspended_by'    => $userId,
                ])->save();

                $this->logEvent($account, 'SERVICE_SUSPENSION_RECLASSIFIED', 'info', $reason, [
                    'suspension_type' => $type,
                    'suspended_by'    => $userId,
                    'note'            => 'suspension reclassified (service was already suspended)',
                ]);
            }

            Log::info("ServiceLifecycleService: suspend skipped, already suspended ({$account->username})", [
                'suspension_type' => $account->suspension_type,
            ]);

            return false;
        }

        // Network access consequences — the access method covers both the
        // router and RADIUS sides in one call (no duplicate RADIUS requests).
        $accessMethod = $this->accessMethods->resolve($account);
        $networkOk = $accessMethod->suspend($account);

        // Single authoritative state write — keeps `status` and
        // `service_state` in lockstep and records why (SL2).
        $account->forceFill([
            'status'          => 'suspended',
            'service_state'   => ClientAccount::STATE_SUSPENDED,
            'suspension_type' => $type,
            'suspended_by'    => $userId,
            'suspended_at'    => now(),
        ])->save();

        $this->logEvent($account, 'SERVICE_SUSPENDED', 'warning', $reason, [
            'suspension_type' => $type,
            'suspended_by'    => $userId,
            'network_ok'      => $networkOk,
        ]);

        Log::info("ServiceLifecycleService: suspended {$account->username}", [
            'suspension_type' => $type,
            'network_ok'      => $networkOk,
        ]);

        return true;
    }

    /**
     * Activate/restore a service.
     *
     * @param bool $force when true this is an EXPLICIT administrative restore
     *                    and an administrative hold is lifted; billing-driven
     *                    callers must leave this false so admin holds survive.
     *
     * @return bool true when the service is active after the call, false when
     *              the restore was blocked by an administrative hold.
     */
    public function activate(ClientAccount $account, ?string $reason = null, bool $force = false, ?int $userId = null): bool
    {
        // SL2 — billing reconciliation (and any other non-administrative
        // caller) must never override an explicit administrative hold.
        if ($account->hasAdministrativeHold() && !$force) {
            Log::info("ServiceLifecycleService: restore blocked by administrative hold ({$account->username})", [
                'reason' => $reason,
            ]);

            $this->logEvent($account, 'SERVICE_RESTORE_BLOCKED', 'warning', $reason, [
                'suspension_type' => ClientAccount::SUSPENSION_ADMIN,
                'note'            => 'restore blocked: administrative hold may only be lifted by an explicit administrative restore',
            ]);

            return false;
        }

        // Idempotency: already fully active (both fields agree).
        if ($account->service_state === ClientAccount::STATE_ACTIVE
            && $account->status === 'active') {
            // Clear any stale suspension classification while we're here.
            if ($account->suspension_type !== null) {
                $account->forceFill([
                    'suspension_type' => null,
                    'suspended_by'    => null,
                ])->save();
            }

            Log::info("ServiceLifecycleService: activate skipped, already active ({$account->username})");

            return true;
        }

        $accessMethod = $this->accessMethods->resolve($account);
        $networkOk = $accessMethod->restore($account);

        $account->forceFill([
            'status'          => 'active',
            'service_state'   => ClientAccount::STATE_ACTIVE,
            'suspension_type' => null,
            'suspended_by'    => null,
            'restored_at'     => now(),
        ])->save();

        $this->logEvent($account, 'SERVICE_ACTIVATED', 'info', $reason, [
            'administrative_restore' => $force,
            'restored_by'            => $userId,
            'network_ok'             => $networkOk,
        ]);

        Log::info("ServiceLifecycleService: activated {$account->username}", [
            'administrative_restore' => $force,
            'network_ok'             => $networkOk,
        ]);

        return true;
    }

    /**
     * Truthful entitlement evaluation (Section 7): a client is entitled when
     * its profile is in an entitlement-bearing status and it carries no
     * overdue debt. This is the single rule used by reconciliation, the
     * model helper and API responses — no fabricated values anywhere.
     */
    public function isEntitled(ClientAccount $account): bool
    {
        $client = $account->client;

        if (!$client) {
            return false;
        }

        if (!in_array($client->status, ['active', 'trial', 'provisioned'])) {
            return false;
        }

        // Prepaid entitlement is genuinely bounded by the paid validity
        // window. Once a prepaid service's expiry passes, it is no longer
        // entitled — regardless of what the DB status column says.
        // (Core ISP Gate — Sections 26 & 27.)
        if ($account->type === 'prepaid'
            && $account->expiry_date !== null
            && $account->expiry_date->isPast()) {
            return false;
        }

        return !$client->invoices()
            ->whereIn('status', ['overdue', 'unpaid'])
            ->where('due_date', '<', now())
            ->exists();
    }

    /**
     * Reconcile billing entitlement with network state for all tenants.
     *
     * Decision model (SL2):
     *   IF the service carries an administrative hold:
     *       do NOT automatically restore — count as skipped.
     *   ELSE IF the client is NOT entitled and the service is active:
     *       billing-suspend it.
     *   ELSE IF the client IS entitled and the service is suspended
     *   (billing-suspended or unclassified):
     *       restore it.
     *
     * Reconciliation also repairs legacy divergence: it never leaves
     * `status` and `service_state` contradicting each other through a
     * supported path, because suspend()/activate() always write both.
     *
     * @return array{suspended:int,restored:int,checked:int,admin_hold_skipped:int}
     */
    public function reconcileAll(): array
    {
        $stats = ['suspended' => 0, 'restored' => 0, 'checked' => 0, 'admin_hold_skipped' => 0];

        foreach (\App\Models\Tenant::query()->cursor() as $tenant) {
            \App\Models\Tenant::setCurrent($tenant);

            try {
                $accounts = ClientAccount::with('client')
                    ->whereIn('status', ['active', 'suspended'])
                    ->get();

                foreach ($accounts as $account) {
                    $client = $account->client;
                    if (!$client) {
                        continue;
                    }

                    $stats['checked']++;

                    $entitled = $this->isEntitled($account);

                    if (!$entitled && $account->status === 'active') {
                        $this->suspend($account, 'entitlement-reconcile: no active entitlement', self::SUSPENSION_BILLING);
                        $stats['suspended']++;
                    } elseif ($entitled && $account->status === 'suspended') {
                        // SL2 — an administrative hold survives reconciliation.
                        // Only an explicit administrative restore lifts it.
                        if ($account->hasAdministrativeHold()) {
                            $stats['admin_hold_skipped']++;
                            continue;
                        }

                        $this->activate($account, 'entitlement-reconcile: entitled');
                        $stats['restored']++;
                    } elseif ($entitled
                        && $account->status === 'active'
                        && $account->service_state === ClientAccount::STATE_SUSPENDED
                        && !$account->hasAdministrativeHold()) {
                        // Divergence repair: status claims active while the
                        // network state is suspended and the client is
                        // entitled — restore through the authority.
                        $this->activate($account, 'entitlement-reconcile: state divergence repair');
                        $stats['restored']++;
                    }
                }
            } finally {
                \App\Models\Tenant::setCurrent(null);
            }
        }

        return $stats;
    }

    /**
     * Record a lifecycle audit event (what / when / why / who).
     */
    protected function logEvent(ClientAccount $account, string $eventType, string $severity, ?string $reason, array $context = []): void
    {
        NetworkEvent::create([
            'tenant_id'         => $account->tenant_id,
            'event_type'        => $eventType,
            'severity'          => $severity,
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Service {$account->username} " . match ($eventType) {
                'SERVICE_SUSPENDED'               => "suspended. Reason: {$reason}",
                'SERVICE_ACTIVATED'               => "activated. Reason: {$reason}",
                'SERVICE_RESTORE_BLOCKED'         => "restore blocked. Reason: {$reason}",
                'SERVICE_SUSPENSION_RECLASSIFIED' => "suspension reclassified. Reason: {$reason}",
                default                           => "lifecycle event {$eventType}. Reason: {$reason}",
            },
            'context'           => $context,
            'source'            => 'system',
        ]);
    }
}
