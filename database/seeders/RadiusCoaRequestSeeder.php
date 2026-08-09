<?php

namespace Database\Seeders;

use App\Models\RadiusCoaRequest;
use App\Models\RadiusSession;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class RadiusCoaRequestSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $sessions = RadiusSession::where('tenant_id', $tenant->id)->limit(10)->get();
            $count = 0;

            foreach ($sessions as $session) {
                RadiusCoaRequest::create([
                    'tenant_id' => $tenant->id,
                    'radius_session_id' => $session->id,
                    'request_type' => 'CoA-Request',
                    'status' => 'dispatched',
                    'attributes' => json_encode(['Framed-Protocol' => 'PPP']),
                    'sent_at' => Carbon::now()->subHours(rand(1, 24)),
                ]);
                $count++;
            }

            $this->command->line("  [{$tenant->slug}] {$count} CoA requests seeded.");
        });

        $this->command->info('RadiusCoaRequestSeeder: complete.');
    }
}