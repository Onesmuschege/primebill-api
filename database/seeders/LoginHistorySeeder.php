<?php

namespace Database\Seeders;

use App\Models\LoginHistory;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds login history for real tenant users (mix of success and failure).
 * Idempotent guard.
 */
class LoginHistorySeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $users = User::where('tenant_id', $tenant->id)->get();
            if ($users->isEmpty()) {
                $this->command->warn("LoginHistorySeeder [{$tenant->slug}]: No users found. Skipping.");
                return;
            }

            if (LoginHistory::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Login history already present — skipped.");
                return;
            }

            $created = 0;
            foreach ($users as $uIndex => $user) {
                // 5 entries per user: mix of successes and failures
                for ($i = 0; $i < 5; $i++) {
                    $success = $i % 4 !== 0;
                    $loggedIn = Carbon::now()->subDays(($uIndex * 3 + $i * 2) % 30)->setTime(9 + $i % 8, ($i * 9) % 60);
                    LoginHistory::create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'ip_address' => '196.202.' . (1 + $tenant->id) . '.' . (20 + $uIndex * 5 + $i),
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Seeder/1.0',
                        'device' => ['Chrome on Windows', 'Safari on macOS', 'Chrome on Android'][$i % 3],
                        'location' => 'Nairobi, KE',
                        'success' => $success,
                        'failure_reason' => $success ? null : 'Invalid credentials',
                        'logged_in_at' => $loggedIn,
                        'logged_out_at' => $success ? $loggedIn->copy()->addHours(3) : null,
                        'created_at' => $loggedIn,
                        'updated_at' => $loggedIn,
                    ]);
                    $created++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} login history records seeded.");
        });

        $this->command->info('LoginHistorySeeder: complete.');
    }
}
