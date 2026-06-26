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

// Parse FRONTEND_URL into an array, trim whitespace, drop empty strings.
$frontendOrigins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('FRONTEND_URL', '')))
));

// In local development, always allow localhost variants if no FRONTEND_URL is set.
// This avoids the silent degradation where prod also accepts localhost because
// FRONTEND_URL was not configured.
if (empty($frontendOrigins) && app()->environment('local', 'testing')) {
    $frontendOrigins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
    ];
}

return [

    // ---------------------------------------------------------------------------
    // Paths that CORS headers are applied to.
    // sanctum/csrf-cookie is required if you ever use cookie-based auth.
    // ---------------------------------------------------------------------------
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    // ---------------------------------------------------------------------------
    // Allowed HTTP methods.
    //
    // Explicit list instead of ['*'] — required when supports_credentials is true.
    // The CORS spec (Fetch Standard §3.2.3) prohibits the combination of
    // Access-Control-Allow-Credentials: true and Access-Control-Allow-Methods: *
    // in preflight responses. Some browsers (Safari, older Chrome) block it.
    // ---------------------------------------------------------------------------
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    // ---------------------------------------------------------------------------
    // Allowed origins.
    // Populated from FRONTEND_URL env var (comma-separated for multiple domains).
    // ---------------------------------------------------------------------------
    'allowed_origins' => $frontendOrigins,

    'allowed_origins_patterns' => [],

    // ---------------------------------------------------------------------------
    // Allowed request headers.
    //
    // Explicit list for the same reason as allowed_methods.
    // Authorization      — Sanctum Bearer token
    // Content-Type       — JSON request bodies
    // Accept             — JSON response negotiation
    // X-Requested-With   — Laravel CSRF/AJAX detection
    // X-XSRF-TOKEN       — Sanctum CSRF cookie (portal SPA)
    // ---------------------------------------------------------------------------
    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    // ---------------------------------------------------------------------------
    // Exposed headers — headers the browser JS can read from responses.
    // X-Total-Count lets the frontend read pagination totals without parsing
    // the full response body (used by TanStack Query pagination).
    // ---------------------------------------------------------------------------
    'exposed_headers' => [
        'X-Total-Count',
    ],

    // ---------------------------------------------------------------------------
    // Preflight cache duration in seconds.
    //
    // 0 = browser re-sends OPTIONS before every credentialed request (default).
    // 86400 = browser caches preflight result for 24 hours (browser maximum).
    //
    // With max_age 0, a dashboard loading 8 endpoints fires up to 8 OPTIONS
    // requests before the actual calls. Set to 86400 in production.
    // ---------------------------------------------------------------------------
    'max_age' => (int) env('CORS_MAX_AGE', app()->environment('production') ? 86400 : 0),

    // ---------------------------------------------------------------------------
    // Must be true for Sanctum Bearer token + cookie-based portal auth.
    // When true, allowed_origins MUST NOT contain '*'.
    // ---------------------------------------------------------------------------
    'supports_credentials' => true,
];