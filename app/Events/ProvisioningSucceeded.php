<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Provisioning completed on every enforcement layer (router + RADIUS).
 *
 * Dispatched by ProvisioningService — the single authority on provisioning
 * outcomes — so subscribers see exactly what the operator/audit sees. The
 * entity is the ClientAccount; context carries the operation, status and
 * idempotency key for the logical attempt.
 */
class ProvisioningSucceeded
{
    use Dispatchable, InteractsWithAutomation, SerializesModels;

    public function __construct(public mixed $entity = null, public array $context = [])
    {
    }
}