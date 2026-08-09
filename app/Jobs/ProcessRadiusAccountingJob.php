<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\RadiusSession;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

        // FreeRADIUS is a shared, server-side service — the same username can
        // (and typically does) exist in multiple tenants. We MUST resolve the
        // owning account first, then establish that tenant's context before
        // writing any tenant-scoped RadiusSession row, otherwise the session
        // could be recorded under the wrong tenant or fail tenant-isolation.
        $account = ClientAccount::query()
            ->withoutTenantScope()
            ->where('username', $username)
            ->first();

        if (!$account || !$account->tenant_id) {
            Log::warning('ProcessRadiusAccountingJob: no tenant-owning account found for username', [
                'username' => $username,
            ]);
            return;
        }

        $tenant = Tenant::find($account->tenant_id);
        if (!$tenant) {
            Log::warning('ProcessRadiusAccountingJob: owning tenant missing', [
                'username'  => $username,
                'tenant_id' => $account->tenant_id,
            ]);
            return;
        }

        Tenant::setCurrent($tenant);

        try {
            RadiusSession::updateOrCreate(
                [
                    'username'      => $username,
                    'session_start' => $this->payload['session_start'] ?? now(),
                ],
                [
                    'client_account_id' => $account->id,
                    'ip_address'        => $this->payload['framed_ip'] ?? $this->payload['Framed-IP-Address'] ?? null,
                    'bytes_in'          => (int) ($this->payload['bytes_in'] ?? $this->payload['Acct-Input-Octets'] ?? 0),
                    'bytes_out'         => (int) ($this->payload['bytes_out'] ?? $this->payload['Acct-Output-Octets'] ?? 0),
                    'session_stop'      => ($this->payload['status'] ?? '') === 'Stop' ? now() : null,
                    'status'            => ($this->payload['status'] ?? 'Interim') === 'Stop' ? 'closed' : 'active',
                    'tenant_id'         => $tenant->id,
                ]
            );
        } finally {
            Tenant::setCurrent(null);
        }
    }
}
