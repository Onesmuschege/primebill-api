<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSubscriptionLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();

        if (!$tenant) {
            return $next($request);
        }

        // Check if tenant is suspended
        if ($tenant->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact support.',
                'data' => null,
                'errors' => ['account' => ['Your account has been suspended.']],
            ], 403);
        }

        // Check if tenant is archived
        if ($tenant->isArchived()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been archived.',
                'data' => null,
                'errors' => ['account' => ['Your account has been archived.']],
            ], 403);
        }

        // Check client limits
        if ($request->isMethod('POST') && $request->routeIs('clients.store')) {
            if (!$tenant->canAddClient()) {
                return response()->json([
                    'success' => false,
                    'message' => "You have reached your plan limit of {$tenant->max_clients} clients. Please upgrade your plan.",
                    'data' => null,
                    'errors' => ['limit' => ["Maximum {$tenant->max_clients} clients allowed"]],
                ], 422);
            }
        }

        return $next($request);
    }
}
