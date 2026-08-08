<?php

namespace App\Services\Network;

use App\Models\ClientAccount;

/**
 * Access Method Strategy Interface
 *
 * Each access method (PPPoE, HotSpot, Static IP, DHCP) implements this
 * interface to provide the provisioning, suspension, restoration, and
 * deprovisioning operations specific to that access method.
 */
interface AccessMethodInterface
{
    /**
     * Provision the service on the network (router + RADIUS).
     */
    public function provision(ClientAccount $account, string $plainPassword): bool;

    /**
     * Suspend the service (disable RADIUS auth + disconnect active sessions).
     */
    public function suspend(ClientAccount $account): bool;

    /**
     * Restore the service (re-enable RADIUS auth).
     */
    public function restore(ClientAccount $account): bool;

    /**
     * Deprovision the service (remove from router + RADIUS).
     */
    public function deprovision(ClientAccount $account): bool;

    /**
     * Apply a bandwidth policy change (CoA where supported).
     */
    public function applyBandwidthPolicy(ClientAccount $account, array $policy): bool;

    /**
     * Disconnect an active session.
     */
    public function disconnectSession(ClientAccount $account, ?string $sessionId = null): bool;
}
