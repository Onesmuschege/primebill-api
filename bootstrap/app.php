<?php

// bootstrap/app.php
// Replace your existing bootstrap/app.php with this.
//
// MIDDLEWARE ORDER MATTERS:
// TrustProxies must run first — it rewrites the request so all subsequent
// middleware see the correct scheme (https), host, and client IP.
// HandleCors must run second — CORS headers must be present on ALL responses
// including 401s, 404s, and 500s. If CORS runs after auth middleware, a failed
// auth check returns a 401 with no CORS headers and the browser reports a CORS
// error instead of an auth error — extremely confusing to debug.

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ── Global middleware (runs on every request) ─────────────────────
        // ORDER IS CRITICAL — do not reorder these three.
        $middleware->use([
            \App\Http\Middleware\TrustProxies::class,           // 1st — fix scheme/host
            \Illuminate\Http\Middleware\HandleCors::class,       // 2nd — CORS on all responses
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Http\Middleware\ValidatePostSize::class,
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        // ── API middleware group ───────────────────────────────────────────
        $middleware->api(append: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class . ':api',
        ]);

        // ── Route middleware aliases ───────────────────────────────────────
        $middleware->alias([
            'auth'       => \App\Http\Middleware\Authenticate::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'throttle'   => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for all API exceptions — no HTML error pages on the API.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                // Don't leak stack traces in production.
                $message = app()->environment('production')
                    ? ($status < 500 ? $e->getMessage() : 'Server error. Please try again.')
                    : $e->getMessage();

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], $status);
            }
        });
    })
    ->create();