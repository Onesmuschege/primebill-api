<?php

namespace App\Services\Olt;

use App\Models\Olt;
use App\Models\Ont;
use App\Models\PonPort;
use App\Traits\LogsAudit;
use Illuminate\Support\Facades\DB;

/**
 * High-level OLT operations. Resolves the correct vendor adapter and
 * orchestrates ONT registration, signal polling, and removal while keeping
 * the local DB (Olt / PonPort / Ont) in sync with the physical device.
 */
class OltService
{
    use LogsAudit;

    protected string $auditAlias = 'OltService';

    /**
     * Map an OLT vendor to its adapter instance.
     */
    public function adapterFor(Olt $olt): OltAdapterInterface
    {
        $vendor = strtolower($olt->vendor ?? '');

        return match ($vendor) {
            'huawei'    => new HuaweiOltAdapter(),
            'zte'       => new ZteOltAdapter(),
            'fiberhome' => new FiberHomeOltAdapter(),
            'vsol'      => new VsolOltAdapter(),
            default     => new MockOltAdapter(),
        };
    }

    public function testConnection(Olt $olt): bool
    {
        return $this->adapterFor($olt)->testConnection($olt->toArray());
    }

    /**
     * Register an ONT on a PON port. Creates/updates the local Ont row and
     * provisions it on the physical OLT.
     */
    public function registerOnt(Olt $olt, int $ponPortId, array $data): Ont
    {
        $adapter = $this->adapterFor($olt);

        return DB::transaction(function () use ($olt, $ponPortId, $data, $adapter) {
            $ponPort = $olt->ponPorts()->findOrFail($ponPortId);

            $ont = Ont::firstOrCreate(
                ['tenant_id' => $olt->tenant_id, 'serial' => $data['serial']],
                [
                    'olt_id'       => $olt->id,
                    'pon_port_id'  => $ponPort->id,
                    'mac_address'  => $data['mac_address'] ?? null,
                    'vendor'       => $data['vendor'] ?? $olt->vendor,
                    'model'        => $data['model'] ?? null,
                    'firmware'     => $data['firmware'] ?? null,
                    'status'       => 'provisioning',
                    'client_account_id' => $data['client_account_id'] ?? null,
                    'tenant_id'    => $olt->tenant_id,
                ]
            );

            // If the ONT already existed, update its current placement.
            $ont->update([
                'olt_id'      => $olt->id,
                'pon_port_id' => $ponPort->id,
                'status'      => 'provisioning',
            ]);

            $adapter->registerOnt($olt->toArray(), $ponPort->name, $ont->serial);

            // Increment the registered_onts counter on the PON port.
            $ponPort->increment('registered_onts');

            return $ont;
        });
    }

    /**
     * Remove an ONT from the physical OLT and mark it faulty/deregistered.
     */
    public function removeOnt(Olt $olt, Ont $ont): bool
    {
        $adapter = $this->adapterFor($olt);

        return DB::transaction(function () use ($olt, $ont, $adapter) {
            $adapter->removeOnt($olt->toArray(), $ont->serial);

            $ont->update(['status' => 'faulty', 'last_seen' => now()]);

            if ($ont->pon_port_id) {
                PonPort::where('id', $ont->pon_port_id)
                    ->where('registered_onts', '>', 0)
                    ->decrement('registered_onts');
            }

            return true;
        });
    }

    /**
     * Poll optical signal for a single ONT and update rx/tx + status.
     */
    public function pollOntSignal(Olt $olt, Ont $ont): array
    {
        $adapter = $this->adapterFor($olt);
        $signal  = $adapter->getOntSignal($olt->toArray(), $ont->serial);

        $ont->update([
            'rx_signal' => $signal['rx'] ?? null,
            'tx_signal' => $signal['tx'] ?? null,
            'status'    => ($signal['online'] ?? false) ? 'online' : 'offline',
            'last_seen' => now(),
        ]);

        return $signal;
    }

    /**
     * Poll signal for every ONT on an OLT.
     *
     * @return array{olt_id: int, polled: int, online: int, offline: int}
     */
    public function pollAllOntSignals(Olt $olt): array
    {
        $olts = $olt->onts()->get();

        $polled  = 0;
        $online  = 0;
        $offline = 0;

        foreach ($olts as $ont) {
            $signal = $this->pollOntSignal($olt, $ont);
            $polled++;
            ($signal['online'] ?? false) ? $online++ : $offline++;
        }

        return [
            'olt_id'  => $olt->id,
            'polled'  => $polled,
            'online'  => $online,
            'offline' => $offline,
        ];
    }
}
