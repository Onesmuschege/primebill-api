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

];
