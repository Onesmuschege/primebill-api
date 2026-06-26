<?php

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeder Credentials
    |--------------------------------------------------------------------------
    | Passwords used by AdminUserSeeder. Set SEED_ADMIN_PASSWORD and
    | SEED_STAFF_PASSWORD in your .env file. Never call env() directly
    | outside config files — seeders must use config() so values are
    | available even when the config cache is active.
    */
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD', 'Admin@123'),
    'seed_staff_password' => env('SEED_STAFF_PASSWORD', 'Staff@123'),

];