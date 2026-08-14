<?php

namespace Database\Seeders;

use App\Models\RadiusCoaRequest;
use App\Models\RadiusSession;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

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
                    'tenant_id'         => $tenant->id,
                    'radius_session_id' => $session->id,
                    'action'            => 'CoA-Request',
                    'status'            => 'dispatched',
                    'attributes'        => ['Framed-Protocol' => 'PPP'],
                ]);
                $count++;
            }

            $this->command->line("  [{$tenant->slug}] {$count} CoA requests seeded.");
        });

        $this->command->info('RadiusCoaRequestSeeder: complete.');
    }
}