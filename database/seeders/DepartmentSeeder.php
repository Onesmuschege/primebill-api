<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $departments = [
                ['name' => 'Technical Support', 'description' => 'Technical support department', 'email' => 'support@example.com'],
                ['name' => 'Customer Care', 'description' => 'Customer service department', 'email' => 'care@example.com'],
                ['name' => 'Network Operations', 'description' => 'NOC team', 'email' => 'noc@example.com'],
            ];

            foreach ($departments as $dept) {
                Department::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $dept['name']],
                    array_merge($dept, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($departments) . " departments seeded.");
        });

        $this->command->info('DepartmentSeeder: complete.');
    }
}
