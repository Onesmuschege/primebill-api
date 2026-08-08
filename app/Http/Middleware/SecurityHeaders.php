<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->withHeaders([
            // Prevent MIME type sniffing
            'X-Content-Type-Options' => 'nosniff',
            // Prevent clickjacking
            'X-Frame-Options' => 'DENY',
            // XSS protection
            'X-XSS-Protection' => '1; mode=block',
            // Referrer policy
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Permissions policy
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            // Content Security Policy (adjust as needed for your frontend)
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'",
            // Strict Transport Security (HTTPS only)
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ]);
    }
}
