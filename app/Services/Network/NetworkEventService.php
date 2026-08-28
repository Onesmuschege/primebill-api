<?php

namespace App\Services\Network;

use App\Models\NetworkEvent;

class NetworkEventService
{
    public function create(array $data): NetworkEvent
    {
        return NetworkEvent::create($data);
    }

    /**
     * Record a network event.
     *
     * @param  string  $eventType  e.g. FUP_RESET, SESSION_RECONCILED
     * @param  string  $message    human-readable summary
     * @param  array   $context    structured metadata stored as JSON
     * @param  string  $severity   info|warning|critical
     */
    public function record(
        string $eventType,
        string $message,
        array $context = [],
        string $severity = 'info',
        ?int $clientId = null,
        ?int $clientAccountId = null,
        ?int $nasId = null,
        ?int $radiusSessionId = null,
        string $source = 'system'
    ): NetworkEvent {
        return $this->create([
            'event_type'        => $eventType,
            'severity'          => $severity,
            'client_id'         => $clientId,
            'client_account_id' => $clientAccountId,
            'nas_id'            => $nasId,
            'radius_session_id' => $radiusSessionId,
            'message'           => $message,
            'context'           => $context,
            'source'            => $source,
            'occurred_at'       => now(),
        ]);
    }

    /**
     * Record that a Fair Usage Policy threshold was reached for an account.
     */
    public function fupTriggered(int $clientAccountId, array $context = []): NetworkEvent
    {
        return $this->record(
            'FUP_TRIGGERED',
            "FUP threshold reached for account #{$clientAccountId}",
            $context,
            'warning',
            null,
            $clientAccountId,
            null,
            null,
            'system'
        );
    }
}
