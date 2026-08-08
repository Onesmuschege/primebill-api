<?php

namespace App\Services\Olt;

use Illuminate\Support\Facades\Log;

/**
 * Huawei OLT adapter (e.g. MA5600T / MA5800 series).
 *
 * Uses SSH/Telnet CLI commands. In production this would use phpseclib
 * or a dedicated SNMP library. The implementation below logs the
 * intended operations and returns mock-success so the integration tests
 * can validate the full OLT→PON→ONT→ClientAccount chain without
 * hardware.
 */
class HuaweiOltAdapter implements OltAdapterInterface
{
    public function testConnection(array $olt): bool
    {
        Log::info('HuaweiOltAdapter:testConnection', ['host' => $olt['ip_address']]);

        return true;
    }

    public function registerOnt(array $olt, string $ponPort, string $serial): bool
    {
        Log::info('HuaweiOltAdapter:registerOnt', [
            'host'    => $olt['ip_address'],
            'ponPort' => $ponPort,
            'serial'  => $serial,
        ]);

        return true;
    }

    public function removeOnt(array $olt, string $serial): bool
    {
        Log::info('HuaweiOltAdapter:removeOnt', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return true;
    }

    public function getOntSignal(array $olt, string $serial): array
    {
        Log::info('HuaweiOltAdapter:getOntSignal', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return [
            'rx'     => -20.5,
            'tx'     => 2.5,
            'online' => true,
        ];
    }

    public function listOnts(array $olt): array
    {
        Log::info('HuaweiOltAdapter:listOnts', [
            'host' => $olt['ip_address'],
        ]);

        return [];
    }
}
