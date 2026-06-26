<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * TrustProxies
 *
 * WHY THIS IS CRITICAL ON RAILWAY:
 * Railway runs your app behind a load balancer / reverse proxy. Every request
 * arrives at your app over HTTP internally, but the original client connected
 * over HTTPS. Without trusting the proxy, Laravel sees HTTP and:
 *
 *   1. url() and route() helpers generate http:// URLs
 *   2. M-Pesa callback registration sends http:// to Safaricom — REJECTED.
 *      Safaricom requires HTTPS for all callback URLs.
 *   3. Sanctum's secure cookie flag may fail on non-HTTPS detection.
 *   4. redirect() calls go to http://, breaking HSTS.
 *
 * SETTING proxies = '*':
 * On Railway (and most PaaS), the proxy IP changes dynamically — you cannot
 * whitelist a specific IP range. '*' trusts all proxies, which is safe when:
 *   - Your app is not directly internet-accessible (Railway handles ingress)
 *   - You are not reading client IPs for security decisions (use X-Forwarded-For
 *     only for logging, never for auth or rate limiting)
 *
 * REGISTRATION (Laravel 11):
 * In bootstrap/app.php, add inside ->withMiddleware():
 *
 *   $middleware->use([
 *       \App\Http\Middleware\TrustProxies::class,  // ← MUST be first
 *       \Illuminate\Http\Middleware\HandleCors::class,
 *       // ... rest of global middleware
 *   ]);
 */
class TrustProxies extends Middleware
{
    /**
     * Trust all proxies — required for Railway, Heroku, Render, and most PaaS.
     * The '*' wildcard is safe when the app sits behind a PaaS ingress layer.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * Forward all standard proxy headers.
     * HEADER_X_FORWARDED_AWS_ELB added for completeness if you ever move to AWS.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}