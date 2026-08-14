<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a realistic sales pipeline of leads per tenant. Status values come
 * from Lead::STATUSES. Idempotent via tenant-unique deterministic phone.
 */
class LeadSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            if (! $admin) {
                $this->command->warn("LeadSeeder [{$tenant->slug}]: No admin found. Skipping.");
                return;
            }

            $firstNames = ['Brian', 'Lucy', 'Kevin', 'Faith', 'Victor', 'Naomi', 'Dennis', 'Purity', 'Felix', 'Nelly', 'Arnold', 'Sharon'];
            $lastNames = ['Wekesa', 'Atieno', 'Kiprop', 'Chepkemoi', 'Omondi', 'Njeri', 'Kiptoo', 'Awuor', 'Ouma', 'Wambui', 'Mutai', 'Adhiambo'];
            $towns = ['Bungoma Town', 'Webuye', 'Kakamega', 'Kisumu', 'Eldoret', 'Kitale'];

            $sources = Lead::SOURCES;
            $statuses = Lead::STATUSES;
            $created = 0;

            for ($i = 0; $i < 12; $i++) {
                $fn = $firstNames[$i % count($firstNames)];
                $ln = $lastNames[($i * 5) % count($lastNames)];
                $status = $statuses[$i % count($statuses)];
                $source = $sources[($i * 3) % count($sources)];
                $phone = '0755' . str_pad((string) ($tenant->id * 10000 + 2000 + $i), 6, '0', STR_PAD_LEFT);

                $convertedAt = $status === 'converted' ? Carbon::now()->subDays((12 - $i) % 20) : null;

                Lead::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'phone' => $phone],
                    [
                        'tenant_id' => $tenant->id,
                        'first_name' => $fn,
                        'last_name' => $ln,
                        'email' => strtolower($fn . '.' . $ln) . '.lead' . ($i + 1) . '@' . $tenant->slug . '.example',
                        'phone' => $phone,
                        'alt_phone' => '0799' . str_pad((string) ($i + 10), 6, '0', STR_PAD_LEFT),
                        'address' => 'Plot ' . (30 + $i) . ', ' . $towns[$i % count($towns)],
                        'town' => $towns[$i % count($towns)],
                        'county' => 'Bungoma',
                        'source' => $source,
                        'status' => $status,
                        'interest_plan' => 'Business 20Mbps',
                        'notes' => 'Seeded lead via ' . $source,
                        'lost_reason' => $status === 'lost' ? 'Competitor offer accepted' : null,
                        'assigned_to' => $admin->id,
                        'contacted_at' => in_array($status, ['contacted', 'qualified', 'survey_required', 'converted', 'lost']) ? Carbon::now()->subDays(($i + 1) * 2) : null,
                        'qualified_at' => in_array($status, ['qualified', 'survey_required', 'converted', 'lost']) ? Carbon::now()->subDays(($i + 1) * 2 - 1) : null,
                        'converted_at' => $convertedAt,
                        'created_at' => Carbon::now()->subDays(($i + 2) * 3),
                        'updated_at' => Carbon::now()->subDays(($i + 2) * 3),
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} leads seeded.");
        });

        $this->command->info('LeadSeeder: complete.');
    }
}
