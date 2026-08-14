<?php

namespace Database\Seeders;

use App\Models\MfaRecoveryCode;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds MFA recovery codes for real tenant users. Codes are stored as
 * HMAC-SHA256 hashes (never plaintext). Idempotent guard.
 */
class MfaRecoveryCodeSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $users = User::where('tenant_id', $tenant->id)->get();
            if ($users->isEmpty()) {
                $this->command->warn("MfaRecoveryCodeSeeder [{$tenant->slug}]: No users found. Skipping.");
                return;
            }

            if (MfaRecoveryCode::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] MFA recovery codes already present — skipped.");
                return;
            }

            $created = 0;
            foreach ($users as $uIndex => $user) {
                // 8 single-use recovery codes per user.
                for ($i = 0; $i < 8; $i++) {
                    $plaintext = 'RC-' . $tenant->slug . '-' . $user->id . '-' . $i . '-' . Str::random(8);
                    MfaRecoveryCode::create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'code_hash' => hash_hmac('sha256', $plaintext, (string) config('app.key')),
                        'used' => $uIndex === 0 && $i === 0 ? true : false,
                        'used_at' => $uIndex === 0 && $i === 0 ? Carbon::now()->subDays(10) : null,
                        'expires_at' => Carbon::now()->addDays(180),
                        'attempts' => 0,
                        'last_attempt_at' => null,
                        'metadata' => ['seed' => true],
                    ]);
                    $created++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} MFA recovery codes seeded.");
        });

        $this->command->info('MfaRecoveryCodeSeeder: complete.');
    }
}
