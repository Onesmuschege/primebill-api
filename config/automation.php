<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automation Engine
    |--------------------------------------------------------------------------
    |
    | Release 5 — event-driven ops automation. Events fire from observers,
    | scheduled jobs and request flows; listeners enqueue idempotent jobs
    | to the queue below. Disable in testing to avoid mutating state from
    | observers during the existing suite (set AUTOMATION_ENABLED=false).
    */

    'enabled' => env('AUTOMATION_ENABLED', true),

    'queue' => env('AUTOMATION_QUEUE', 'automation'),

    // Retry / timeout policy applied to every queued automation listener.
    'retry' => [
        'tries'   => (int) env('AUTOMATION_RETRIES', 3),
        'backoff' => [15, 45, 120],
        'timeout' => (int) env('AUTOMATION_TIMEOUT', 300),
    ],

    // Idempotency window — a matching automation_event key suppresses re-runs.
    'idempotency_ttl' => (int) env('AUTOMATION_IDEMPOTENCY_TTL', 86400),

    'log' => [
        'channel' => 'automation',
        'path'    => env('AUTOMATION_LOG_PATH', storage_path('logs/automation.log')),
    ],

    // Events that drive the automation engine.
    'events' => [
        'ClientCreated',
        'ClientUpdated',
        'SubscriptionActivated',
        'SubscriptionSuspended',
        'SubscriptionTerminated',
        'InvoiceGenerated',
        'InvoiceOverdue',
        'PaymentReceived',
        'PaymentFailed',
        'RouterOffline',
        'OLTOffline',
        'TicketCreated',
        'SLABreached',
        'WorkOrderCompleted',
    ],

];
