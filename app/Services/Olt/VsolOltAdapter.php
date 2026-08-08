<?php

namespace App\Services\Olt;

use Illuminate\Support\Facades\Log;

/**
 * VSOL OLT adapter (e.g. V1600D / V3000 series).
 */
class VsolOltAdapter implements OltAdapterInterface
{
    public function testConnection(array $olt): bool
    {
        Log::info('VsolOltAdapter:testConnection', ['host' => $olt['ip_address']]);

        return true;
    }

    public function registerOnt(array $olt, string $ponPort, string $serial): bool
    {
        Log::info('VsolOltAdapter:registerOnt', [
            'host'    => $olt['ip_address'],
            'ponPort' => $ponPort,
            'serial'  => $serial,
        ]);

        return true;
    }

    public function removeOnt(array $olt, string $serial): bool
    {
        Log::info('VsolOltAdapter:removeOnt', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return true;
    }

    public function getOntSignal(array $olt, string $serial): array
    {
        Log::info('VsolOltAdapter:getOntSignal', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return [
            'rx'     => -23.0,
            'tx'     => 1.8,
            'online' => true,
        ];
    }

    public function listOnts(array $olt): array
    {
        Log::info('VsolOltAdapter:listOnts', ['host' => $olt['ip_address']]);

        return [];
    }
}
