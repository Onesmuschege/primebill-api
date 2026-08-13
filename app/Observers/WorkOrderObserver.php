<?php

namespace App\Observers;

use App\Events\WorkOrderCompleted;
use App\Models\WorkOrder;
use App\Services\Automation\Automation;

class WorkOrderObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function updated(WorkOrder $workOrder): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        if (in_array($workOrder->getOriginal('status'), ['pending', 'in_progress', 'assigned', 'scheduled', 'open'], true)
            && $workOrder->status === 'completed') {
            event(new WorkOrderCompleted($workOrder));
        }
    }
}
