<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceOverdue
{
    use Dispatchable, InteractsWithAutomation, SerializesModels;

    public function __construct(public mixed $entity = null, public array $context = [])
    {
    }
}
