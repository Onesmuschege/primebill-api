<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Resolves the current tenant from a request. Callers never care HOW the
 * tenant was identified — today it's "logged-in user's tenant_id" for the
 * dashboard and "URL slug" for the public portal. Adding subdomain or
 * custom-domain resolution later means adding a branch in resolve() here,
 * not touching any controller, model, or the BelongsToTenant trait.
 */
class TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        // Dashboard: an authenticated staff user always belongs to exactly
        // one tenant. This takes priority — if you're logged in, that's
        // your tenant, regardless of what's in the URL.
        if ($request->user()) {
            return $request->user()->tenant_id
                ? Tenant::find($request->user()->tenant_id)
                : null;
        }

        // Public captive portal: /portal/{tenant_slug}/...
        if ($slug = $request->route('tenant_slug')) {
            return Tenant::where('slug', $slug)->where('status', '!=', 'suspended')->first();
        }

        // Future: subdomain resolution would go here —
        // $host = $request->getHost();
        // $subdomain = explode('.', $host)[0] ?? null;
        // if ($subdomain && $subdomain !== 'app') {
        //     return Tenant::where('slug', $subdomain)->first();
        // }

        // Future: custom domain resolution would go here —
        // return Tenant::where('custom_domain', $request->getHost())->first();

        return null;
    }
}
