<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = Tenant::current();

        if (!$tenant) {
            return $next($request);
        }

        if (!$tenant->hasFeature($feature)) {
            return response()->json([
                'success' => false,
                'message' => 'This feature is not available on your current plan. Please upgrade to access it.',
                'data' => null,
                'errors' => ['feature' => ["Feature '{$feature}' requires a plan upgrade"]],
            ], 403);
        }

        return $next($request);
    }
}
