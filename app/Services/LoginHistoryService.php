<?php

namespace App\Services;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;

class LoginHistoryService
{
    /**
     * Record a successful login.
     */
    public function recordLogin(User $user, Request $request): void
    {
        LoginHistory::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device' => $this->parseDevice($request->userAgent()),
            'location' => null,
            'success' => true,
            'logged_in_at' => now(),
            'tenant_id' => $user->tenant_id,
        ]);
    }

    /**
     * Record a failed login attempt.
     */
    public function recordFailedLogin(string $email, Request $request, string $reason): void
    {
        LoginHistory::create([
            'user_id' => null,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device' => $this->parseDevice($request->userAgent()),
            'location' => null,
            'success' => false,
            'failure_reason' => $reason,
            'tenant_id' => null,
        ]);
    }

    /**
     * Record a logout.
     */
    public function recordLogout(User $user, Request $request): void
    {
        $updated = LoginHistory::where('user_id', $user->id)
            ->whereNull('logged_out_at')
            ->where('success', true)
            ->latest()
            ->limit(1)
            ->update(['logged_out_at' => now()]);

        // If there was no open login row (e.g. a token-authenticated session
        // that never went through login, or tests acting as a user directly),
        // still record a logout entry so the audit trail is complete.
        if ($updated === 0) {
            LoginHistory::create([
                'user_id'      => $user->id,
                'email'        => $user->email,
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'device'       => $this->parseDevice($request->userAgent()),
                'location'     => null,
                'success'      => true,
                'logged_in_at' => now(),
                'logged_out_at' => now(),
                'tenant_id'    => $user->tenant_id,
            ]);
        }
    }

    /**
     * Get login history for a user.
     */
    public function getUserHistory(User $user, int $limit = 50)
    {
        return LoginHistory::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent security events (failed logins, etc.).
     */
    public function getSecurityEvents(int $limit = 100)
    {
        return LoginHistory::where('success', false)
            ->orWhere('failure_reason', '!=', null)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Parse device from user agent.
     */
    private function parseDevice(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        if (str_contains($userAgent, 'Windows')) {
            return 'Windows';
        } elseif (str_contains($userAgent, 'Mac')) {
            return 'Mac';
        } elseif (str_contains($userAgent, 'Linux')) {
            return 'Linux';
        } elseif (str_contains($userAgent, 'Android')) {
            return 'Android';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            return 'iOS';
        }

        return 'Unknown';
    }
}
