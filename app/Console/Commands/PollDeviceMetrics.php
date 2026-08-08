<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\MonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 — Network Operations Center.
 *
 * Polls every tenant's devices for CPU/RAM/temp/latency metrics and evaluates
 * them against thresholds. In production this would use SNMP/ICMP adapters;
 * the mock driver simulates readings so the pipeline is testable end-to-end.
 */
class PollDeviceMetrics extends Command
{
    protected $signature = 'network:poll-metrics {--device= : Poll a specific device ID}';

    protected $description = 'Poll device metrics (CPU, RAM, temp, latency) and evaluate alert thresholds';

    public function handle(MonitorService $monitor): int
    {
        $polled  = 0;
        $failed  = 0;
        $deviceOption = $this->option('device');

        if ($deviceOption) {
            $device = Router::find($deviceOption);

            if (!$device) {
                $this->error("Device #{$deviceOption} not found.");

                return self::FAILURE;
            }

            return $this->pollDevice($monitor, $device) ? self::SUCCESS : self::FAILURE;
        }

        $tenants = Tenant::query()->where('status', '!=', 'suspended')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found. Nothing to poll.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            app()->instance('currentTenant', $tenant);

            $devices = Router::query()->where('status', '!=', 'disabled')->get();

            foreach ($devices as $device) {
                if ($this->pollDevice($monitor, $device)) {
                    $polled++;
                } else {
                    $failed++;
                }
            }
        }

        app()->forgetInstance('currentTenant');

        $this->info("Polled {$polled} device(s) across {$tenants->count()} tenant(s); {$failed} failed.");

        return self::SUCCESS;
    }

    protected function pollDevice(MonitorService $monitor, Router $device): bool
    {
        try {
            // Simulated readings — replace with SNMP/ICMP/MikroTik in production.
            $monitor->record($device, 'cpu', round(mt_rand(1000, 9500) / 100, 2), null, '%');
            $monitor->record($device, 'ram', round(mt_rand(1500, 9500) / 100, 2), null, '%');
            $monitor->record($device, 'temp', round(mt_rand(3000, 8000) / 100, 2), null, 'C');
            $monitor->record($device, 'latency', round(mt_rand(1000, 30000) / 100, 2), null, 'ms');

            $device->update(['status' => 'online', 'last_seen' => now()]);

            $this->line("Recorded metrics for {$device->name}");

            return true;
        } catch (\Throwable $e) {
            $device->update(['status' => 'offline']);
            Log::error("PollDeviceMetrics: error polling device {$device->name}: {$e->getMessage()}", [
                'device_id' => $device->id,
            ]);
            $this->error("Error polling {$device->name}: {$e->getMessage()}");

            return false;
        }
    }
}
