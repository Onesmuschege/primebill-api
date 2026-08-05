<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyMpesaCallback
{
    /**
     * Handle an incoming request.
     *
     * Security hardening: M-Pesa callbacks MUST be verified by at least one
     * mechanism. Safaricom's STK/C2B callbacks do NOT send an HMAC signature
     * header — they rely on IP allowlisting. So we require either:
     *   - MPESA_CALLBACK_SIGNATURE_SECRET  (HMAC on X-MPESA-SIGNATURE), OR
     *   - MPESA_CALLBACK_ALLOWED_IPS       (Safaricom IP allowlist)
     *
     * If NEITHER is configured, we refuse to serve callbacks (HTTP 500) rather
     * than silently accepting unauthenticated requests that could credit
     * customer accounts with fake payments.
     */
    public function handle(Request $request, Closure $next)
    {
        $allowedIps      = config('mpesa.callback_allowed_ips', []);
        $signatureSecret = config('mpesa.callback_signature_secret', '');
        $ip              = $request->ip();

        $hasIpAllowlist = !empty($allowedIps) && is_array($allowedIps);
        $hasSignature   = !empty($signatureSecret);

        // If neither verification mechanism is configured, refuse callbacks.
        if (!$hasIpAllowlist && !$hasSignature) {
            Log::critical(
                'MPesa callback is unverified — configure MPESA_CALLBACK_ALLOWED_IPS or MPESA_CALLBACK_SIGNATURE_SECRET'
            );
            return response()->json(['message' => 'Service misconfigured'], 500);
        }

        // IP allowlist check (primary mechanism for real Safaricom callbacks).
        if ($hasIpAllowlist && !in_array($ip, $allowedIps, true)) {
            Log::warning('MPesa callback from disallowed IP: ' . $ip);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // If a signature secret is configured, also verify the HMAC header.
        if ($hasSignature) {
            $header   = $request->header('X-MPESA-SIGNATURE');
            $raw      = $request->getContent();
            $computed = base64_encode(hash_hmac('sha256', $raw, $signatureSecret, true));

            if (empty($header) || !hash_equals((string) $computed, (string) $header)) {
                Log::warning('MPesa callback signature mismatch', ['ip' => $ip]);
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        return $next($request);
    }
}
