<?php

/**
 * CORS Configuration
 *
 * ALLOWED ORIGINS:
 * Set FRONTEND_URL in your .env as a comma-separated list of allowed origins.
 * Railway (backend) example:
 *   FRONTEND_URL=https://app.primebill.co.ke,https://primebill-frontend.vercel.app
 *
 * Vercel preview deployments get a new URL per branch. Add them explicitly or
 * use FRONTEND_URL_EXTRA for ephemeral preview URLs during development.
 *
 * NEVER set FRONTEND_URL=* in production — credentials + wildcard origin is
 * rejected by the CORS spec and blocked by all modern browsers.
 *
 * CREDENTIALS:
 * supports_credentials must be true for Sanctum Bearer-token flow to work
 * correctly with same-site cookie fallback. This also means allowed_origins
 * can never contain '*' — the CORS spec forbids the combination.
 */

$appEnv = env('APP_ENV', 'production');

// Parse FRONTEND_URL into an array, trim whitespace, drop empty strings.
$frontendOrigins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('FRONTEND_URL', '')))
));

// In local development, always allow localhost variants if no FRONTEND_URL is set.
// This avoids the silent degradation where prod also accepts localhost because
// FRONTEND_URL was not configured.
if (empty($frontendOrigins) && in_array($appEnv, ['local', 'testing'])) {
    $frontendOrigins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
    ];
}

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_origins' => $frontendOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [
        'X-Total-Count',
    ],

    'max_age' => (int) env('CORS_MAX_AGE', $appEnv === 'production' ? 86400 : 0),

    'supports_credentials' => true,
];