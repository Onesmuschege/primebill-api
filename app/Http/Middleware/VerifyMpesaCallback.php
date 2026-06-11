<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class VerifyMpesaCallback
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $allowedIps = config('mpesa.callback_allowed_ips', []);
        $signatureSecret = config('mpesa.callback_signature_secret', '');

        $ip = $request->ip();

        // If allowlist is configured, enforce it
        if (!empty($allowedIps) && is_array($allowedIps)) {
            if (!in_array($ip, $allowedIps, true)) {
                Log::warning('MPesa callback from disallowed IP: ' . $ip);
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        // If signature secret is configured, verify X-MPESA-SIGNATURE header
        if (!empty($signatureSecret)) {
            $header = $request->header('X-MPESA-SIGNATURE');
            $raw = $request->getContent();
            $computed = base64_encode(hash_hmac('sha256', $raw, $signatureSecret, true));

            if (empty($header) || !hash_equals((string) $computed, (string) $header)) {
                Log::warning('MPesa callback signature mismatch', ['ip' => $ip]);
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        return $next($request);
    }
}
