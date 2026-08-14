<?php

namespace Database\Seeders;

use App\Models\RadiusDisconnectRequest;
use App\Models\RadiusSession;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RadiusDisconnectRequestSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $sessions = RadiusSession::where('tenant_id', $tenant->id)->limit(10)->get();
            $count = 0;

            foreach ($sessions as $session) {
                RadiusDisconnectRequest::create([
                    'tenant_id'         => $tenant->id,
                    'radius_session_id' => $session->id,
                    'status'            => rand(0, 1) ? 'completed' : 'failed',
                    'reason'            => 'Session timeout',
                    'reason_details'    => 'Disconnect due to idle timeout',
                ]);
                $count++;
            }

            $this->command->line("  [{$tenant->slug}] {$count} disconnect requests seeded.");
        });

        $this->command->info('RadiusDisconnectRequestSeeder: complete.');
    }
}