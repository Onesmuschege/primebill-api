<?php

namespace App\Services\Network;

use App\Models\DeviceMetric;
use App\Models\NetworkAlert;
use App\Models\Router;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 — Network Operations Center.
 *
 * Polls device metrics (via SNMP/ICMP/MikroTik adapters), evaluates them
 * against thresholds, and raises/resolves NetworkAlert records. Alert delivery
 * is dispatched through AlertService so callers can send notifications.
 */
class MonitorService
{
    /**
     * Default alert thresholds per metric type, keyed by metric_type.
     *
     * @var array<string, array{threshold: float, severity: string}>
     */
    protected array $thresholds = [
        'cpu'             => ['threshold' => 85, 'severity' => 'critical'],
        'ram'             => ['threshold' => 85, 'severity' => 'critical'],
        'temp'            => ['threshold' => 75, 'severity' => 'warning'],
        'interface_util'  => ['threshold' => 90, 'severity' => 'warning'],
        'latency'         => ['threshold' => 200, 'severity' => 'warning'],
        'errors'          => ['threshold' => 1, 'severity' => 'warning'],
        'drops'           => ['threshold' => 1, 'severity' => 'warning'],
    ];

    public function __construct(
        protected AlertService $alerts,
        protected NotificationService $notifications
    ) {}

    /**
     * Record a single metric reading for a device and evaluate thresholds.
     */
    public function record(Router $device, string $metricType, float $value, ?string $interface = null, ?string $unit = null): DeviceMetric
    {
        $metric = DeviceMetric::create([
            'device_id'   => $device->id,
            'metric_type' => $metricType,
            'value'       => $value,
            'interface'   => $interface,
            'unit'        => $unit,
            'recorded_at' => now(),
        ]);

        $this->evaluate($device, $metricType, $value, $interface);

        return $metric;
    }

    /**
     * Evaluate a single reading against thresholds and raise an alert if exceeded.
     */
    public function evaluate(Router $device, string $metricType, float $value, ?string $interface = null): void
    {
        $rule = $this->thresholds[$metricType] ?? null;

        if (!$rule) {
            return;
        }

        if ($value > $rule['threshold']) {
            $this->alerts->raise(
                $device,
                $this->alertTypeFor($metricType),
                $rule['severity'],
                "{$metricType} exceeded threshold: {$value} > {$rule['threshold']}",
                $value,
                $rule['threshold'],
                $interface
            );
        } else {
            // Attempt to auto-resolve any open alert for this metric.
            $this->alerts->autoResolve($device, $this->alertTypeFor($metricType), $interface);
        }
    }

    /**
     * Poll a list of devices, updating their status and recording offline alerts.
     */
    public function pollDeviceHealth(iterable $devices): void
    {
        foreach ($devices as $device) {
            $online = $this->ping($device);

            if ($online) {
                $device->update(['status' => 'online', 'last_seen' => now()]);
                $this->alerts->autoResolve($device, 'device_offline', null);
            } else {
                $device->update(['status' => 'offline']);
                $this->alerts->raise(
                    $device,
                    'device_offline',
                    'critical',
                    "Device {$device->name} is offline",
                    null,
                    null,
                    null
                );
            }
        }
    }

    /**
     * Attempt to reach a device. In production this would use SNMP/ICMP.
     * The mock polling command overrides this behaviour.
     */
    protected function ping(Router $device): bool
    {
        return $device->status === 'online';
    }

    protected function alertTypeFor(string $metricType): string
    {
        return match ($metricType) {
            'cpu'            => 'high_cpu',
            'ram'            => 'high_ram',
            'temp'           => 'health_failure',
            'interface_util' => 'high_util',
            'latency'        => 'high_latency',
            'errors', 'drops' => 'interface_down',
            default          => 'health_failure',
        };
    }
}
