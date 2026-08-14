<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds field work orders for real clients and technicians. work_order_number
 * is globally unique so it embeds the tenant id. created_by is required.
 * Idempotent on tenant + work_order_number.
 */
class WorkOrderSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $techs = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'staff'))
                ->get();
            $clients = Client::where('tenant_id', $tenant->id)->where('status', 'active')->get();

            if (! $admin || $clients->isEmpty()) {
                $this->command->warn("WorkOrderSeeder [{$tenant->slug}]: No admin/clients found. Skipping.");
                return;
            }

            $templates = [
                ['type' => 'installation', 'status' => 'completed', 'priority' => 'normal', 'description' => 'Install new FTTH connection with CPE and ONT.' ],
                ['type' => 'repair', 'status' => 'in_progress', 'priority' => 'high', 'description' => 'Repair fiber drop cable at customer premises.'],
                ['type' => 'relocation', 'status' => 'scheduled', 'priority' => 'normal', 'description' => 'Relocate service to new customer address.'],
                ['type' => 'maintenance', 'status' => 'dispatched', 'priority' => 'normal', 'description' => 'Preventive maintenance on customer CPE.'],
                ['type' => 'survey', 'status' => 'scheduled', 'priority' => 'low', 'description' => 'Site survey for new connection feasibility.'],
                ['type' => 'repair', 'status' => 'cancelled', 'priority' => 'normal', 'description' => 'Cancelled repair after customer resolution.'],
                ['type' => 'installation', 'status' => 'completed', 'priority' => 'normal', 'description' => 'Upgrade ONT to gigabit model.'],
            ];

            $created = 0;
            foreach ($templates as $index => $t) {
                $client = $clients[$index % $clients->count()];
                $tech = $techs[$index % max(1, $techs->count())] ?? $admin;

                $number = 'WO-' . $tenant->id . '-' . date('Y') . '-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                $scheduledAt = Carbon::now()->subDays(10 - $index);
                $startedAt = in_array($t['status'], ['in_progress', 'completed', 'dispatched', 'cancelled']) ? $scheduledAt->copy()->addDay() : null;
                $completedAt = in_array($t['status'], ['completed', 'cancelled']) ? $scheduledAt->copy()->addDays(2) : null;

                WorkOrder::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'work_order_number' => $number],
                    [
                        'tenant_id' => $tenant->id,
                        'work_order_number' => $number,
                        'client_id' => $client->id,
                        'type' => $t['type'],
                        'status' => $t['status'],
                        'priority' => $t['priority'],
                        'description' => $t['description'],
                        'notes' => 'Seeded work order',
                        'scheduled_at' => $scheduledAt,
                        'started_at' => $startedAt,
                        'completed_at' => $completedAt,
                        'assigned_to' => $tech->id,
                        'created_by' => $admin->id,
                        'photos' => $t['status'] === 'completed' ? ['https://placehold.co/600x400?text=Installation'] : null,
                        'customer_signature' => $t['status'] === 'completed' ? ['name' => $client->first_name, 'data' => 'data:image/png;base64,SEED'] : null,
                        'completion_notes' => $t['status'] === 'completed' ? 'Job completed successfully. Customer confirmed service.' : null,
                        'completion_latitude' => 0.345,
                        'completion_longitude' => 34.565,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} work orders seeded.");
        });

        $this->command->info('WorkOrderSeeder: complete.');
    }
}
