<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\NetworkEvent;
use App\Models\RadiusSession;
use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Session Reconciliation Service
 *
 * Detects stale/unclosed RADIUS sessions and reconciles them.
 * If Accounting-Stop never arrives (router crash, network failure),
 * sessions are eventually reconciled and closed.
 */
class SessionReconciliationService
{
    public function __construct(
        protected NetworkEventService $networkEvents
    ) {}

    /**
     * Find all sessions that have no Accounting-Stop and are stale.
     * Stale = no interim update within the configured threshold.
     */
    public function findStaleSessions(int $staleMinutes = 5): array
    {
        return RadiusSession::where('status', RadiusSession::STATUS_ONLINE)
            ->where(function ($q) use ($staleMinutes) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes($staleMinutes));
            })
            ->where('session_start', '<', now()->subMinutes($staleMinutes))
            ->with('account')
            ->get()
            ->toArray();
    }

    /**
     * Reconcile stale sessions — mark them as offline.
     */
    public function reconcileStaleSessions(int $staleMinutes = 5): int
    {
        $stale = RadiusSession::where('status', RadiusSession::STATUS_ONLINE)
            ->where(function ($q) use ($staleMinutes) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes($staleMinutes));
            })
            ->where('session_start', '<', now()->subMinutes($staleMinutes))
            ->with('account')
            ->get();

        $count = 0;

        foreach ($stale as $session) {
            $session->update([
                'status'      => RadiusSession::STATUS_OFFLINE,
                'session_stop' => now(),
                'terminate_cause' => 'Lost-Carrier / Stale session (no interim accounting)',
            ]);

            $count++;

            if ($session->account) {
                $this->networkEvents->record(
                    'SESSION_RECONCILED',
                    "Stale session {$session->session_id} for {$session->username} reconciled",
                    [
                        'session_id'       => $session->session_id,
                        'duration_seconds' => $session->session_start
                            ? now()->diffInSeconds($session->session_start)
                            : 0,
                    ],
                    'warning',
                    $session->account->client_id,
                    $session->account->id,
                    $session->nas_id,
                    $session->id,
                    'system'
                );
            }
        }

        Log::info("Session reconciliation: closed {$count} stale sessions");

        return $count;
    }

    /**
     * Cross-reference with router's active sessions to find phantom sessions.
     */
    public function reconcileWithRouter(Router $router, array $routerActiveSessions): int
    {
        $reconciled = 0;

        // Find sessions we think are online but the router doesn't have
        $activeSessions = RadiusSession::where('nas_id', $router->id)
            ->where('status', RadiusSession::STATUS_ONLINE)
            ->get();

        foreach ($activeSessions as $session) {
            $found = false;
            foreach ($routerActiveSessions as $routerSession) {
                if (($routerSession['user'] ?? '') === $session->username ||
                    ($routerSession['name'] ?? '') === $session->username) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $session->update([
                    'status'      => RadiusSession::STATUS_OFFLINE,
                    'session_stop' => now(),
                    'terminate_cause' => 'Session not found on NAS (router-initiated disconnect)',
                ]);
                $reconciled++;

                if ($session->account) {
                    $this->networkEvents->record(
                        'SESSION_TERMINATED',
                        "Session {$session->session_id} for {$session->username} terminated on NAS but no Accounting-Stop received",
                        [],
                        'warning',
                        $session->account->client_id,
                        $session->account->id,
                        $router->id,
                        $session->id,
                        'radius'
                    );
                }
            }
        }

        return $reconciled;
    }
}
