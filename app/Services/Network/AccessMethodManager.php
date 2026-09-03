<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use InvalidArgumentException;

/**
 * Resolves the correct AccessMethodInterface implementation based on
 * the account's access_method field.
 */
class AccessMethodManager
{
    public function __construct(
        protected PppoeAccessService $pppoe,
        protected HotspotAccessService $hotspot,
        protected StaticIpAccessService $staticIp,
        protected DhcpAccessService $dhcp
    ) {}

    public function resolve(ClientAccount $account): AccessMethodInterface
    {
        // Legacy rows and accounts created before Network Core Phase A have
        // no explicit access_method; PPPoE is the platform default.
        $method = $account->access_method ?: ClientAccount::ACCESS_PPPOE;

        return match ($method) {
            ClientAccount::ACCESS_PPPOE   => $this->pppoe,
            ClientAccount::ACCESS_HOTSPOT => $this->hotspot,
            ClientAccount::ACCESS_STATIC  => $this->staticIp,
            ClientAccount::ACCESS_DHCP    => $this->dhcp,
            default => throw new InvalidArgumentException(
                "Unsupported access method: {$method}"
            ),
        };
    }

    public function resolveForMethod(string $method): AccessMethodInterface
    {
        return match ($method) {
            ClientAccount::ACCESS_PPPOE   => $this->pppoe,
            ClientAccount::ACCESS_HOTSPOT => $this->hotspot,
            ClientAccount::ACCESS_STATIC  => $this->staticIp,
            ClientAccount::ACCESS_DHCP    => $this->dhcp,
            default => throw new InvalidArgumentException(
                "Unsupported access method: {$method}"
            ),
        };
    }
}
