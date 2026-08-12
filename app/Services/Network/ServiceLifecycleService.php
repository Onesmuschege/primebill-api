<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\NetworkEvent;
use App\Services\Radius\RadiusControlService as RadiusControl;
use Illuminate\Support\Facades\Log;

class ServiceLifecycleService
{
    public function __construct(
        protected AccessMethodManager $accessMethods,
        protected RadiusControl $radiusControl
    ) {}

    public function suspend(ClientAccount $account, ?string $reason = null): void
    {
        $accessMethod = $this->accessMethods->resolve($account);

        $routerOk = $accessMethod->suspend($account);
        $radiusOk = $this->radiusControl->suspend($account);

        $account->update([
            'status'      => 'suspended',
            'service_state' => ClientAccount::STATE_SUSPENDED,
            'suspended_at' => now(),
        ]);

        NetworkEvent::create([
            'tenant_id'         => $account->tenant_id,
            'event_type'        => 'SERVICE_SUSPENDED',
            'severity'          => 'warning',
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Service {$account->username} suspended. Reason: {$reason}",
            'context'           => [
                'reason'     => $reason,
                'router_ok'  => $routerOk,
                'radius_ok'  => $radiusOk,
            ],
            'source' => 'system',
        ]);

        Log::info("ServiceLifecycleService: suspended {$account->username}", [
            'router_ok' => $routerOk,
            'radius_ok' => $radiusOk,
        ]);
    }

    public function activate(ClientAccount $account, ?string $reason = null): void
    {
        $accessMethod = $this->accessMethods->resolve($account);

        $routerOk = $accessMethod->restore($account);
        $radiusOk = $this->radiusControl->activate($account);

        $account->update([
            'status'      => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
            'restored_at' => now(),
        ]);

        NetworkEvent::create([
            'tenant_id'         => $account->tenant_id,
            'event_type'        => 'SERVICE_ACTIVATED',
            'severity'          => 'info',
            'client_id'         => $account->client_id,
            'client_account_id' => $account->id,
            'nas_id'            => $account->nas_id,
            'message'           => "Service {$account->username} activated. Reason: {$reason}",
            'context'           => [
                'reason'     => $reason,
                'router_ok'  => $routerOk,
                'radius_ok'  => $radiusOk,
            ],
            'source' => 'system',
        ]);

        Log::info("ServiceLifecycleService: activated {$account->username}", [
            'router_ok' => $routerOk,
            'radius_ok' => $radiusOk,
        ]);
    }

    /**
     * Reconcile billing entitlement with network state for all tenants.
     *
     * For every active account:
     *   - if its client is NOT entitled (suspended/inactive/disabled) or the
     *     client has overdue debt, suspend the service (billing suspension ->
     *     RADIUS/MikroTik restriction, then audit).
     *   - if the account is suspended but its client IS entitled and has no
     *     overdue debt, restore the service (payment -> reactivation ->
     *     RADIUS/MikroTik, then audit).
     *
     * This is the enforcement loop behind `network:reconcile-entitlements`,
     * keeping billing state and network state from silently diverging.
     *
     * @return array{suspended:int,restored:int,checked:int}
     */
    public function reconcileAll(): array
    {
        $stats = ['suspended' => 0, 'restored' => 0, 'checked' => 0];

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

                    $hasOverdue = $client->invoices()
                        ->whereIn('status', ['overdue', 'unpaid'])
                        ->where('due_date', '<', now())
                        ->exists();

                    $entitled = in_array($client->status, ['active', 'trial', 'provisioned'])
                        && !$hasOverdue;

                    if (!$entitled && $account->status === 'active') {
                        $this->suspend($account, 'entitlement-reconcile: no active entitlement');
                        $stats['suspended']++;
                    } elseif ($entitled && $account->status === 'suspended') {
                        $this->activate($account, 'entitlement-reconcile: entitled');
                        $stats['restored']++;
                    }
                }
            } finally {
                \App\Models\Tenant::setCurrent(null);
            }
        }

        return $stats;
    }
}
