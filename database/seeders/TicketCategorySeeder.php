<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Tenant;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds ticket categories per department. Idempotent on tenant + code.
 */
class TicketCategorySeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $categories = [
                ['code' => 'CONNECTIVITY', 'name' => 'Connectivity', 'icon' => 'wifi', 'color' => '#2563eb', 'priority' => 3, 'is_active' => true, 'description' => 'Connection down, intermittent service or no internet.'],
                ['code' => 'SPEED', 'name' => 'Speed / Performance', 'icon' => 'speed', 'color' => '#f59e0b', 'priority' => 2, 'is_active' => true, 'description' => 'Slow speeds and latency complaints.'],
                ['code' => 'BILLING', 'name' => 'Billing', 'icon' => 'receipt', 'color' => '#10b981', 'priority' => 2, 'is_active' => true, 'description' => 'Invoice, payment and charging queries.'],
                ['code' => 'EQUIPMENT', 'name' => 'Equipment', 'icon' => 'router', 'color' => '#8b5cf6', 'priority' => 2, 'is_active' => true, 'description' => 'Router, ONT and CPE issues.'],
                ['code' => 'ACCOUNT', 'name' => 'Account', 'icon' => 'user', 'color' => '#ef4444', 'priority' => 1, 'is_active' => true, 'description' => 'Login, credentials and account profile.'],
                ['code' => 'INSTALLATION', 'name' => 'Installation', 'icon' => 'wrench', 'color' => '#06b6d4', 'priority' => 1, 'is_active' => true, 'description' => 'New installs, relocations and upgrades.'],
                ['code' => 'OTHER', 'name' => 'Other', 'icon' => 'dots', 'color' => '#64748b', 'priority' => 0, 'is_active' => false, 'description' => 'Anything else.'],
            ];

            $departments = Department::where('tenant_id', $tenant->id)->get();
            $created = 0;

            foreach ($categories as $c) {
                $department = $departments->first() ?? null;
                if (in_array($c['code'], ['CONNECTIVITY', 'SPEED', 'EQUIPMENT'])) {
                    $department = $departments->firstWhere('name', 'Technical Support') ?? $departments->first();
                } elseif ($c['code'] === 'BILLING') {
                    $department = $departments->firstWhere('name', 'Customer Care') ?? $departments->first();
                }

                TicketCategory::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $c['code']],
                    [
                        'tenant_id' => $tenant->id,
                        'department_id' => $department?->id,
                        'name' => $c['name'],
                        'code' => $c['code'],
                        'description' => $c['description'],
                        'icon' => $c['icon'],
                        'color' => $c['color'],
                        'priority' => $c['priority'],
                        'is_active' => $c['is_active'],
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} ticket categories seeded.");
        });

        $this->command->info('TicketCategorySeeder: complete.');
    }
}
