<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Provisioning (or a lifecycle enforcement operation) did NOT complete on at
 * least one layer. Context carries the partial status, the failure reason and
 * the idempotency key so retry pipelines can attribute attempts.
 */
class ProvisioningFailed
{
    use Dispatchable, InteractsWithAutomation, SerializesModels;

    public function __construct(public mixed $entity = null, public array $context = [])
    {
    }
}