<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Network Adapter Drivers
    |--------------------------------------------------------------------------
    |
    | Use "mock" for local development and tests. Use "mikrotik" and
    | "freeradius" in production when hardware/services are available.
    |
    */

    'router_driver' => env('NETWORK_ROUTER_DRIVER', 'mock'),

'radius_driver' => env('NETWORK_RADIUS_DRIVER', 'mock'),

    'olt_driver' => env('NETWORK_OLT_DRIVER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | FreeRADIUS Database Connection
    |--------------------------------------------------------------------------
    |
    | FreeRADIUS typically uses a dedicated MySQL database. Point this
    | connection at your FreeRADIUS SQL schema in production.
    |
    */

    'radius_connection' => env('RADIUS_DB_CONNECTION', 'radius'),

    /*
    |--------------------------------------------------------------------------
    | Default MikroTik PPP Profile
    |--------------------------------------------------------------------------
    */

    'default_ppp_profile' => env('MIKROTIK_DEFAULT_PROFILE', 'default'),

    'default_hotspot_profile' => env('MIKROTIK_DEFAULT_HOTSPOT_PROFILE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Provisioning Queue
    |--------------------------------------------------------------------------
    */

    'provisioning_queue' => env('PROVISIONING_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | RADIUS Accounting Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret that FreeRADIUS must send in the X-RADIUS-SECRET header
    | when POSTing accounting data to /api/webhooks/radius/accounting.
    | If empty, the webhook rejects all requests (fail-closed).
    |
    */

    'radius_webhook_secret' => env('RADIUS_WEBHOOK_SECRET', ''),

    /*
    /*
    |--------------------------------------------------------------------------
    | RADIUS CoA / Disconnect (dynamic authorization, RFC 5176)
    |--------------------------------------------------------------------------
    |
    | Live session control. When enabled, rate-limit changes and session
    | disconnects are sent as real RADIUS CoA-Request (43) /
    | Disconnect-Request (40) packets to the router's CoA port using the
    | router's shared secret. CoA is conditional on router support: a NAK,
    | timeout or unreachable router is reported truthfully — never turned
    | into a fabricated success.
    |
    */

    'coa_enabled' => env('RADIUS_COA_ENABLED', true),
    'coa_timeout_seconds' => env('RADIUS_COA_TIMEOUT', 3.0),
    'coa_retries' => env('RADIUS_COA_RETRIES', 1),

    /*
    |--------------------------------------------------------------------------
    | Router Health Probing
    |--------------------------------------------------------------------------
    */

    'health_probe_enabled' => env('ROUTER_HEALTH_PROBE_ENABLED', true),

    // Seconds after which a last successful probe/sync is considered stale
    // and a previously-HEALTHY router degrades to DEGRADED (Section 44).
    'router_stale_after_seconds' => env('ROUTER_HEALTH_STALE_AFTER', 900),

    /*
    |--------------------------------------------------------------------------
    | Session Stale Threshold (minutes)
    |--------------------------------------------------------------------------
    |
    | Minutes without RADIUS interim accounting before a session is
    | considered stale and eligible for reconciliation.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Grace Period (days)
    |--------------------------------------------------------------------------
    |
    | Number of days after the invoice due date before service is suspended.
    |
    */

    'grace_period_days' => env('GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Session Stale Threshold (minutes)
    |--------------------------------------------------------------------------
    |
    | Minutes without RADIUS interim accounting before a session is
    | considered stale and eligible for reconciliation.
    |
    */

    'stale_session_minutes' => env('STALE_SESSION_MINUTES', 5),

];
