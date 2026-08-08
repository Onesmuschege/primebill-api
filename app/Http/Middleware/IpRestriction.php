<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IpRestriction — optional per-user IP allowlist.
 *
 * If the authenticated user has an `allowed_ips` array (nullable JSON column),
 * the request's IP address must be in that list. Users with no allowed_ips
 * set (null) are unrestricted — this is a permissive opt-in guard.
 *
 * Apply this middleware to routes that should respect IP restrictions:
 *   Route::middleware(['auth:sanctum', 'tenant', 'ip.restriction'])->...
 *
 * It is deliberately NOT applied globally; only sensitive staff routes
 * (admin panels, network config, billing) should carry it. Portal routes
 * and captive-portal routes do not need it.
 */
class IpRestriction
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No user? Let the auth middleware handle it.
        if (!$user) {
            return $next($request);
        }

        $allowedIps = $user->allowed_ips;

        // No restriction configured — allow.
        if (empty($allowedIps)) {
            return $next($request);
        }

        $allowedIps = is_array($allowedIps) ? $allowedIps : json_decode($allowedIps, true) ?? [];

        if (empty($allowedIps)) {
            return $next($request);
        }

        $requestIp = $request->ip();

        $matched = false;
        foreach ($allowedIps as $ip) {
            // Simple string match
            if ($ip === $requestIp) {
                $matched = true;
                break;
            }

            // CIDR notation (e.g. 192.168.1.0/24)
            if (str_contains($ip, '/') && $this->ipInCidr($requestIp, $ip)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied from this IP address.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if an IPv4 address falls within a CIDR range.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }

        $rangeIp = $parts[0];
        $prefix = (int) $parts[1];

        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $rangeLong = ip2long($rangeIp);

        if ($ipLong === false || $rangeLong === false) {
            return false;
        }

        $mask = -1 << (32 - $prefix);

        return ($ipLong & $mask) === ($rangeLong & $mask);
    }
}
