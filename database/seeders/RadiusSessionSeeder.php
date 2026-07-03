<?php

namespace Database\Seeders;

use App\Models\ClientAccount;
use App\Models\RadiusSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RadiusSessionSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = ClientAccount::where('status', 'active')->get();

        if ($accounts->isEmpty()) {
            $this->command->warn('No active ClientAccounts found — skipping RadiusSessionSeeder.');
            return;
        }

        // ~35% of active accounts currently online — gives the dashboard
        // realistic "Active Users" / "Top Downloaders" numbers instead of 0.
        $onlineCount = max(1, (int) ceil($accounts->count() * 0.35));
        $onlineAccounts = $accounts->random(min($onlineCount, $accounts->count()));

        foreach ($onlineAccounts as $account) {
            RadiusSession::create([
                'username'          => $account->username ?? 'user' . $account->id,
                'client_account_id' => $account->id,
                'ip_address'        => $this->randomIp(),
                'bytes_in'          => random_int(50_000_000, 3_000_000_000),   // 50MB–3GB uploaded
                'bytes_out'         => random_int(200_000_000, 15_000_000_000), // 200MB–15GB downloaded
                'session_start'     => now()->subMinutes(random_int(5, 600)),
                'session_stop'      => null,
                'status'            => 'active',
            ]);
        }

        // A handful of closed sessions from earlier today/yesterday, for
        // historical realism (not counted as "active" by the dashboard).
        $closedAccounts = $accounts->random(min(15, $accounts->count()));

        foreach ($closedAccounts as $account) {
            $start = now()->subHours(random_int(2, 48));
            RadiusSession::create([
                'username'          => $account->username ?? 'user' . $account->id,
                'client_account_id' => $account->id,
                'ip_address'        => $this->randomIp(),
                'bytes_in'          => random_int(10_000_000, 1_000_000_000),
                'bytes_out'         => random_int(50_000_000, 5_000_000_000),
                'session_start'     => $start,
                'session_stop'      => $start->copy()->addMinutes(random_int(10, 300)),
                'status'            => 'stopped',
            ]);
        }

        $this->command->info(
            "RadiusSessionSeeder: {$onlineAccounts->count()} active sessions, {$closedAccounts->count()} closed sessions."
        );
    }

    private function randomIp(): string
    {
        return '10.' . random_int(0, 20) . '.' . random_int(0, 255) . '.' . random_int(2, 254);
    }
}