<?php

namespace App\Observers;

use App\Events\OLTOffline;
use App\Models\Olt;
use App\Services\Automation\Automation;

class OltObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function updated(Olt $olt): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        if ($olt->status === 'offline' && $olt->getOriginal('status') !== 'offline') {
            event(new OLTOffline($olt));
        }
    }
}
