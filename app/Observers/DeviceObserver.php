<?php

namespace App\Observers;

use App\Events\RouterOffline;
use App\Models\Device;
use App\Services\Automation\Automation;

class DeviceObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function updated(Device $device): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        $before = $device->getOriginal('status');
        if ($device->status === 'offline' && $before !== 'offline') {
            event(new RouterOffline($device));
        }
    }
}
