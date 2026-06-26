<?php

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
        // ── Custom global middleware (runs FIRST, before framework defaults) ──
        // TrustProxies MUST be first to fix scheme/host for all downstream code
        $middleware->prepend(\App\Http\Middleware\TrustProxies::class);

        // ── Use statefulApi() for standard API middleware stack ──
        // This includes CORS handling, rate limiting, and stateless auth setup
        $middleware->statefulApi();

        // ── Route middleware aliases ───────────────────────────────────────
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'mpesa.callback' => \App\Http\Middleware\ValidateMpesaCallback::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
