<?php

namespace App\Services\Network;

use App\Models\NetworkAlert;
use App\Models\Router;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 — Network Operations Center alert lifecycle.
 *
 * Creates, acknowledges, and resolves NetworkAlert records. Delivery of
 * notifications (SMS / in-app / email) is dispatched via NotificationService.
 */
class AlertService
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    /**
     * Raise a new alert for a device, avoiding duplicates for the same
     * open alert_type + interface combination.
     */
    public function raise(
        Router $device,
        string $alertType,
        string $severity = 'warning',
        ?string $message = null,
        ?float $metricValue = null,
        ?float $threshold = null,
        ?string $interface = null
    ): NetworkAlert {
        $existing = NetworkAlert::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->when($interface, fn ($q) => $q->where('interface', $interface))
            ->first();

        if ($existing) {
            // Refresh the existing alert rather than creating a duplicate.
            $existing->update([
                'severity'     => $severity,
                'message'      => $message ?? $existing->message,
                'metric_value' => $metricValue ?? $existing->metric_value,
                'threshold'    => $threshold ?? $existing->threshold,
            ]);

            return $existing;
        }

        $alert = NetworkAlert::create([
            'device_id'    => $device->id,
            'alert_type'   => $alertType,
            'severity'     => $severity,
            'message'      => $message ?? "{$alertType} alert",
            'status'       => 'open',
            'metric_value' => $metricValue,
            'threshold'    => $threshold,
            'interface'    => $interface,
        ]);

        Log::warning("NetworkAlert raised: {$alertType} on {$device->name}", [
            'device_id' => $device->id,
            'alert_id'  => $alert->id,
            'severity'  => $severity,
        ]);

        $this->dispatchNotification($alert);

        return $alert;
    }

    /**
     * Acknowledge an open alert.
     */
    public function acknowledge(NetworkAlert $alert, User $user): NetworkAlert
    {
        $alert->update([
            'status'           => 'acknowledged',
            'acknowledged_at'  => now(),
            'acknowledged_by'  => $user->id,
        ]);

        return $alert;
    }

    /**
     * Resolve an open/acknowledged alert.
     */
    public function resolve(NetworkAlert $alert, ?User $user = null): NetworkAlert
    {
        $alert->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $user?->id,
        ]);

        return $alert;
    }

    /**
     * Auto-resolve any open alert for a device + type (optionally interface).
     */
    public function autoResolve(Router $device, string $alertType, ?string $interface = null): void
    {
        NetworkAlert::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->whereIn('status', ['open', 'acknowledged'])
            ->when($interface, fn ($q) => $q->where('interface', $interface))
            ->update([
                'status'      => 'resolved',
                'resolved_at' => now(),
            ]);
    }

    /**
     * Resolve all open alerts for a device (e.g. when it comes back online).
     */
    public function resolveAllForDevice(Router $device): void
    {
        NetworkAlert::query()
            ->where('device_id', $device->id)
            ->where('status', '!=', 'resolved')
            ->update([
                'status'      => 'resolved',
                'resolved_at' => now(),
            ]);
    }

    protected function dispatchNotification(NetworkAlert $alert): void
    {
        // In-app notification placeholder — production would route through
        // SmsService / EmailService based on tenant notification preferences.
        Log::info('NetworkAlert notification dispatched', [
            'alert_id'   => $alert->id,
            'alert_type' => $alert->alert_type,
            'device_id'  => $alert->device_id,
        ]);
    }
}
