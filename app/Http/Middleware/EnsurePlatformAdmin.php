<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Gates every /api/platform/* route. Distinct from the 'permission' and
 * 'role' middleware aliases already in use elsewhere — those check Spatie
 * permissions *within* a tenant; this checks the is_platform_admin column,
 * which sits outside tenant scoping entirely.
 *
 * Deliberately does NOT apply the 'tenant' middleware group — platform
 * routes have no tenant_slug and query models via
 * ModelClass::withoutTenantScope() explicitly, one at a time, rather than
 * having a "current tenant" at all.
 */
class EnsurePlatformAdmin
{
    use ApiResponse;

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->is_platform_admin) {
            return $this->error('Forbidden', null, 403);
        }

        return $next($request);
    }
}
