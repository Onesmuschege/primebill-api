<?php

namespace App\Services\Radius;

use App\Models\ClientAccount;
use App\Services\Network\NetworkEventService;

class RadiusControlService
{
    public function __construct(
        protected NetworkEventService $networkEventService
    ) {}

    public function suspend(ClientAccount $account): bool
    {
        // In a full implementation, this would send a CoA or disconnect request.
        // For now, delegate to the adapter through ProvisioningService pattern.
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
        // CoA implementation placeholder.
        return true;
    }
}
