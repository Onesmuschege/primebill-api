<?php

namespace App\Services\Olt;

use Illuminate\Support\Facades\Log;

/**
 * FiberHome OLT adapter (e.g. AN5516 / AN5506 series).
 */
class FiberHomeOltAdapter implements OltAdapterInterface
{
    public function testConnection(array $olt): bool
    {
        Log::info('FiberHomeOltAdapter:testConnection', ['host' => $olt['ip_address']]);

        return true;
    }

    public function registerOnt(array $olt, string $ponPort, string $serial): bool
    {
        Log::info('FiberHomeOltAdapter:registerOnt', [
            'host'    => $olt['ip_address'],
            'ponPort' => $ponPort,
            'serial'  => $serial,
        ]);

        return true;
    }

    public function removeOnt(array $olt, string $serial): bool
    {
        Log::info('FiberHomeOltAdapter:removeOnt', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return true;
    }

    public function getOntSignal(array $olt, string $serial): array
    {
        Log::info('FiberHomeOltAdapter:getOntSignal', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return [
            'rx'     => -21.0,
            'tx'     => 2.2,
            'online' => true,
        ];
    }

    public function listOnts(array $olt): array
    {
        Log::info('FiberHomeOltAdapter:listOnts', ['host' => $olt['ip_address']]);

        return [];
    }
}
