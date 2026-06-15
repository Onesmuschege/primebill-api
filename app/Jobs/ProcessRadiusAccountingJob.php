<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\RadiusSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRadiusAccountingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(): void
    {
        $username = $this->payload['username'] ?? $this->payload['User-Name'] ?? null;

        if (!$username) {
            return;
        }

        $account = ClientAccount::where('username', $username)->first();

        RadiusSession::updateOrCreate(
            [
                'username'      => $username,
                'session_start' => $this->payload['session_start'] ?? now(),
            ],
            [
                'client_account_id' => $account?->id,
                'ip_address'        => $this->payload['framed_ip'] ?? $this->payload['Framed-IP-Address'] ?? null,
                'bytes_in'          => (int) ($this->payload['bytes_in'] ?? $this->payload['Acct-Input-Octets'] ?? 0),
                'bytes_out'         => (int) ($this->payload['bytes_out'] ?? $this->payload['Acct-Output-Octets'] ?? 0),
                'session_stop'      => ($this->payload['status'] ?? '') === 'Stop' ? now() : null,
                'status'            => ($this->payload['status'] ?? 'Interim') === 'Stop' ? 'closed' : 'active',
            ]
        );
    }
}
