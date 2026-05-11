<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

class RateLimiter
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function handle(Request $request, Closure $next)
    {
        $key = $this->resolveRequestSignature($request);
        $limit = 100; // requests per minute
        $decay = 1; // minute

        if ($this->limiter->tooManyAttempts($key, $limit, $decay)) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded',
                'retry_after' => $this->limiter->availableIn($key),
            ], 429);
        }

        $this->limiter->hit($key, $decay);

        return $next($request)
            ->header('X-RateLimit-Limit', $limit)
            ->header('X-RateLimit-Remaining', $this->limiter->remaining($key, $limit));
    }

    protected function resolveRequestSignature(Request $request)
    {
        return sha1(implode('|', [
            $request->method(),
            $request->getHost(),
            $request->user()?->id ?? $request->ip(),
        ]));
    }
}
