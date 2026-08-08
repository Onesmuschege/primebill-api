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
}
