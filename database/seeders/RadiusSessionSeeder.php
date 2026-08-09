<?php

namespace Database\Seeders;

use App\Models\ClientAccount;
use App\Models\RadiusSession;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RadiusSessionSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $accounts = ClientAccount::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->get();

            if ($accounts->isEmpty()) {
                $this->command->warn("RadiusSessionSeeder [{$tenant->slug}]: No active ClientAccounts - skipping.");
                return;
            }

            // ~35% of active accounts currently online
            $onlineCount = max(1, (int) ceil($accounts->count() * 0.35));
            $onlineAccounts = $accounts->random(min($onlineCount, $accounts->count()));

            foreach ($onlineAccounts as $account) {
                RadiusSession::create([
                    'tenant_id'         => $tenant->id,
                    'username'          => $account->username ?? 'user' . $account->id,
                    'client_account_id' => $account->id,
                    'ip_address'        => $this->randomIp(),
                    'bytes_in'          => random_int(50_000_000, 3_000_000_000),
                    'bytes_out'         => random_int(200_000_000, 15_000_000_000),
                    'nas_id'            => $account->nas_id,
                    'session_start'     => now()->subMinutes(random_int(5, 600)),
                    'status'            => 'active',
                ]);
            }

            // Closed sessions from earlier today/yesterday
            $closedAccounts = $accounts->random(min(15, $accounts->count()));

            foreach ($closedAccounts as $account) {
                $start = now()->subHours(random_int(2, 48));
                RadiusSession::create([
                    'tenant_id'         => $tenant->id,
                    'username'          => $account->username ?? 'user' . $account->id,
                    'client_account_id' => $account->id,
                    'ip_address'        => $this->randomIp(),
                    'bytes_in'          => random_int(10_000_000, 1_000_000_000),
                    'bytes_out'         => random_int(50_000_000, 5_000_000_000),
                    'nas_id'            => $account->nas_id,
                    'session_start'     => $start,
                    'session_stop'      => $start->copy()->addMinutes(random_int(10, 300)),
                    'status'            => 'stopped',
                ]);
            }

            $this->command->line("  [{$tenant->slug}] " . $onlineAccounts->count() . " active, " . $closedAccounts->count() . " closed sessions.");
        });

        $this->command->info('RadiusSessionSeeder: complete.');
    }

    private function randomIp(): string
    {
        return '10.' . random_int(0, 20) . '.' . random_int(0, 255) . '.' . random_int(2, 254);
    }
}
