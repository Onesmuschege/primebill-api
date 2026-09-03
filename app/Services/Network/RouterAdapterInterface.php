<?php

namespace App\Services\Network;

interface RouterAdapterInterface
{
    public function createUser(array $data): bool;

    public function deleteUser(string $username): bool;

    public function suspendUser(string $username): bool;

    public function unsuspendUser(string $username): bool;

    /**
     * Terminate live sessions for a username WITHOUT deleting the credential.
     *
     * This must remove the active PPPoE/hotspot session on the router so a
     * suspended/expired/terminated customer is genuinely offline. It must NOT
     * delete the router user — a disconnect is not a deprovisioning
     * (Core ISP Gate — Section 20).
     */
    public function disconnectSession(string $username): bool;

    public function testConnection(): bool;
}
