<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function __construct(protected TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant) {
            app()->instance('currentTenant', $tenant);
        }

        return $next($request);
    }
}