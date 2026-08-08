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
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'ip.restriction' => \App\Http\Middleware\IpRestriction::class,
            // Gates /api/platform/* — cross-tenant PrimeBill-operator routes.
            // Deliberately separate from 'role'/'permission': those check
            // Spatie roles WITHIN a tenant, this checks the is_platform_admin
            // column, which sits outside tenant scoping entirely.
            'platform_admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'auth.harden' => \App\Http\Middleware\AuthenticationHardening::class,
            'security.headers' => \App\Http\Middleware\SecurityHeaders::class,
        ]);

    })
->withExceptions(function (Exceptions $exceptions) {
        // Audit every authorization failure (including Spatie's
        // UnauthorizedException, which is a subclass of HttpException) so
        // suspicious patterns of repeated denied access can be detected.
        $exceptions->render(function (Throwable $exception, \Illuminate\Http\Request $request) {
            $isAuthz = $exception instanceof \Illuminate\Auth\Access\AuthorizationException
                || $exception instanceof \Spatie\Permission\Exceptions\UnauthorizedException;

            if ($isAuthz && $request->expectsJson()) {
                try {
                    $user = $request->user();
                    if ($user) {
                        \App\Models\SystemLog::create([
                            'tenant_id'  => \App\Models\Tenant::current()?->id,
                            'user_id'    => $user->id,
                            'action'     => 'security.permission_denied',
                            'model'      => $exception->getMessage() ?: null,
                            'model_id'   => null,
                            'ip_address' => $request->ip(),
                            'user_agent' => $request->userAgent(),
                            'new_values' => ['route' => $request->path(), 'method' => $request->method()],
                        ]);
                    }
                } catch (Throwable $e) {
                    // Never let auditing break the response path.
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            return null;
        });
    })
    ->create();
