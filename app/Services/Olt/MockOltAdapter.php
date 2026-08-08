<?php

namespace App\Services\Olt;

use Illuminate\Support\Facades\Log;

/**
 * Mock OLT adapter — used for local development and tests, mirroring the
 * MockRouterAdapter / MockRadiusAdapter pattern.
 */
class MockOltAdapter implements OltAdapterInterface
{
    public function testConnection(array $olt): bool
    {
        Log::info('MockOltAdapter:testConnection', ['host' => $olt['ip_address'] ?? null]);

        return true;
    }

    public function registerOnt(array $olt, string $ponPort, string $serial): bool
    {
        Log::info('MockOltAdapter:registerOnt', [
            'host'    => $olt['ip_address'] ?? null,
            'ponPort' => $ponPort,
            'serial'  => $serial,
        ]);

        return true;
    }

    public function removeOnt(array $olt, string $serial): bool
    {
        Log::info('MockOltAdapter:removeOnt', [
            'host'   => $olt['ip_address'] ?? null,
            'serial' => $serial,
        ]);

        return true;
    }

    public function getOntSignal(array $olt, string $serial): array
    {
        Log::info('MockOltAdapter:getOntSignal', [
            'host'   => $olt['ip_address'] ?? null,
            'serial' => $serial,
        ]);

        return [
            'rx'     => -20.0,
            'tx'     => 2.4,
            'online' => true,
        ];
    }

    public function listOnts(array $olt): array
    {
        Log::info('MockOltAdapter:listOnts', ['host' => $olt['ip_address'] ?? null]);

        return [];
    }
}
