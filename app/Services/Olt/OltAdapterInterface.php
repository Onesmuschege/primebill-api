<?php

namespace App\Services\Olt;

/**
 * Contract every OLT vendor adapter must satisfy.
 *
 * Mirrors the MikroTik / RADIUS adapter pattern: a thin vendor-specific
 * transport layer that the higher-level OltService calls without caring
 * which vendor it is talking to.
 */
interface OltAdapterInterface
{
    /**
     * Verify connectivity to the OLT.
     */
    public function testConnection(array $olt): bool;

    /**
     * Register a new ONU/ONT by serial number on the given PON port.
     */
    public function registerOnt(array $olt, string $ponPort, string $serial): bool;

    /**
     * Remove an ONU/ONT by serial number.
     */
    public function removeOnt(array $olt, string $serial): bool;

    /**
     * Read the current optical signal (rx/tx dBm) for an ONU.
     *
     * @return array{rx: float|null, tx: float|null, online: bool}
     */
    public function getOntSignal(array $olt, string $serial): array;

    /**
     * List all ONUs discovered on an OLT.
     *
     * @return array<int, array{serial: string, mac: string|null, rx: float|null, tx: float|null, online: bool}>
     */
    public function listOnts(array $olt): array;
}
