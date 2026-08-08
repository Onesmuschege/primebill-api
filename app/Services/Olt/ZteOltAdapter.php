<?php

namespace App\Services\Olt;

use Illuminate\Support\Facades\Log;

/**
 * ZTE OLT adapter (e.g. C320 / C600 / ZXA10 series).
 */
class ZteOltAdapter implements OltAdapterInterface
{
    public function testConnection(array $olt): bool
    {
        Log::info('ZteOltAdapter:testConnection', ['host' => $olt['ip_address']]);

        return true;
    }

    public function registerOnt(array $olt, string $ponPort, string $serial): bool
    {
        Log::info('ZteOltAdapter:registerOnt', [
            'host'    => $olt['ip_address'],
            'ponPort' => $ponPort,
            'serial'  => $serial,
        ]);

        return true;
    }

    public function removeOnt(array $olt, string $serial): bool
    {
        Log::info('ZteOltAdapter:removeOnt', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return true;
    }

    public function getOntSignal(array $olt, string $serial): array
    {
        Log::info('ZteOltAdapter:getOntSignal', [
            'host'   => $olt['ip_address'],
            'serial' => $serial,
        ]);

        return [
            'rx'     => -22.0,
            'tx'     => 2.0,
            'online' => true,
        ];
    }

    public function listOnts(array $olt): array
    {
        Log::info('ZteOltAdapter:listOnts', ['host' => $olt['ip_address']]);

        return [];
    }
}
