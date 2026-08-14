<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Tenant;
use App\Models\TicketQueue;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds ticket queues per department. Idempotent on tenant + code.
 */
class TicketQueueSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $queues = [
                ['code' => 'GENERAL', 'name' => 'General Support', 'priority' => 0, 'status' => 'active', 'description' => 'General enquiries and non-technical requests.'],
                ['code' => 'TECHNICAL', 'name' => 'Technical Support', 'priority' => 2, 'status' => 'active', 'description' => 'Connectivity, speed and device troubleshooting.'],
                ['code' => 'BILLING', 'name' => 'Billing & Payments', 'priority' => 2, 'status' => 'active', 'description' => 'Invoices, payments, credit notes and refunds.'],
                ['code' => 'EMERGENCY', 'name' => 'Emergency', 'priority' => 4, 'status' => 'active', 'description' => 'Critical outages and network emergencies.'],
                ['code' => 'SALES', 'name' => 'Sales & Onboarding', 'priority' => 0, 'status' => 'inactive', 'description' => 'New connections and plan upgrades.'],
            ];

            $departments = Department::where('tenant_id', $tenant->id)->get();
            $created = 0;

            foreach ($queues as $q) {
                $department = $departments->first() ?? null;
                if ($q['code'] === 'TECHNICAL') {
                    $department = $departments->firstWhere('name', 'Network Operations') ?? $departments->first();
                } elseif ($q['code'] === 'BILLING') {
                    $department = $departments->firstWhere('name', 'Customer Care') ?? $departments->first();
                }

                TicketQueue::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $q['code']],
                    [
                        'tenant_id' => $tenant->id,
                        'department_id' => $department?->id,
                        'name' => $q['name'],
                        'code' => $q['code'],
                        'description' => $q['description'],
                        'status' => $q['status'],
                        'priority' => $q['priority'],
                        'assigned_to' => $admin?->id,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} ticket queues seeded.");
        });

        $this->command->info('TicketQueueSeeder: complete.');
    }
}
