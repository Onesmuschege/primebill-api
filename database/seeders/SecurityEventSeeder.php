<?php

namespace Database\Seeders;

use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds realistic security events per tenant. Idempotent guard.
 */
class SecurityEventSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $users = User::where('tenant_id', $tenant->id)->get();
            if ($users->isEmpty()) {
                $this->command->warn("SecurityEventSeeder [{$tenant->slug}]: No users found. Skipping.");
                return;
            }

            if (SecurityEvent::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Security events already present — skipped.");
                return;
            }

            $events = [
                ['event' => 'login_failed', 'severity' => 'warning', 'category' => 'authentication', 'source' => 'web'],
                ['event' => 'login_success', 'severity' => 'info', 'category' => 'authentication', 'source' => 'web'],
                ['event' => 'password_changed', 'severity' => 'info', 'category' => 'authentication', 'source' => 'web'],
                ['event' => 'mfa_enabled', 'severity' => 'info', 'category' => 'authentication', 'source' => 'api'],
                ['event' => 'suspicious_activity', 'severity' => 'critical', 'category' => 'security', 'source' => 'system'],
                ['event' => 'api_key_created', 'severity' => 'info', 'category' => 'authorization', 'source' => 'api'],
                ['event' => 'session_revoked', 'severity' => 'warning', 'category' => 'authorization', 'source' => 'web'],
                ['event' => 'ip_restriction_blocked', 'severity' => 'critical', 'category' => 'authorization', 'source' => 'web'],
            ];

            $admin = $users->first();
            $created = 0;

            foreach ($events as $i => $ev) {
                $resolved = $i === 4 || $i === 7;
                SecurityEvent::create([
                    'tenant_id' => $tenant->id,
                    'event' => $ev['event'],
                    'severity' => $ev['severity'],
                    'category' => $ev['category'],
                    'description' => 'Seeded security event: ' . str_replace('_', ' ', $ev['event']),
                    'metadata' => ['seed' => true, 'user_agent' => 'Mozilla/5.0 (Seeded)'],
                    'ip_address' => '196.201.' . (1 + $tenant->id) . '.' . (10 + $i * 7),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Seeder/1.0',
                    'source' => $ev['source'],
                    'is_resolved' => $resolved,
                    'resolved_at' => $resolved ? Carbon::now()->subHours($i + 1) : null,
                    'resolved_by' => $resolved ? $admin->id : null,
                    'created_at' => Carbon::now()->subDays($i + 1)->setTime(9, 15),
                    'updated_at' => Carbon::now()->subDays($i + 1)->setTime(9, 15),
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} security events seeded.");
        });

        $this->command->info('SecurityEventSeeder: complete.');
    }
}
