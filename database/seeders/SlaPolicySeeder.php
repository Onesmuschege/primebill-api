<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\TicketCategory;
use App\Models\Tenant;
use App\Models\SlaPolicy;
use App\Models\SlaRule;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds SLA policies (and rules) per tenant. Idempotent on tenant + code.
 */
class SlaPolicySeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $policies = [
                [
                    'code' => 'STANDARD', 'name' => 'Standard Support',
                    'priority_level' => 0, 'response_time_minutes' => 240, 'resolution_time_minutes' => 2880,
                    'escalation_enabled' => true, 'escalation_after_minutes' => 720, 'is_active' => true,
                ],
                [
                    'code' => 'PREMIUM', 'name' => 'Premium Support',
                    'priority_level' => 1, 'response_time_minutes' => 60, 'resolution_time_minutes' => 720,
                    'escalation_enabled' => true, 'escalation_after_minutes' => 180, 'is_active' => true,
                ],
                [
                    'code' => 'ENTERPRISE', 'name' => 'Enterprise & Business',
                    'priority_level' => 2, 'response_time_minutes' => 30, 'resolution_time_minutes' => 360,
                    'escalation_enabled' => true, 'escalation_after_minutes' => 90, 'is_active' => true,
                ],
                [
                    'code' => 'EMERGENCY', 'name' => 'Emergency SLA',
                    'priority_level' => 3, 'response_time_minutes' => 15, 'resolution_time_minutes' => 120,
                    'escalation_enabled' => true, 'escalation_after_minutes' => 45, 'is_active' => true,
                ],
            ];

            $departments = Department::where('tenant_id', $tenant->id)->get();
            $categories = TicketCategory::where('tenant_id', $tenant->id)->get();
            $created = 0;
            $ruleCount = 0;

            foreach ($policies as $p) {
                $department = $departments->firstWhere('name', 'Technical Support') ?? $departments->first();
                $category = $categories->firstWhere('code', 'CONNECTIVITY') ?? $categories->first();

                $businessHours = [
                    'mon' => ['08:00', '18:00'],
                    'tue' => ['08:00', '18:00'],
                    'wed' => ['08:00', '18:00'],
                    'thu' => ['08:00', '18:00'],
                    'fri' => ['08:00', '18:00'],
                ];

                $policy = SlaPolicy::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $p['code']],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $p['name'],
                        'code' => $p['code'],
                        'description' => $p['name'] . ' service levels.',
                        'department_id' => $department?->id,
                        'ticket_queue_id' => null,
                        'ticket_category_id' => $category?->id,
                        'priority_level' => $p['priority_level'],
                        'response_time_minutes' => $p['response_time_minutes'],
                        'resolution_time_minutes' => $p['resolution_time_minutes'],
                        'business_hours' => $businessHours,
                        'apply_on_weekends' => $p['code'] === 'EMERGENCY',
                        'apply_on_holidays' => $p['code'] === 'EMERGENCY',
                        'escalation_enabled' => $p['escalation_enabled'],
                        'escalation_after_minutes' => $p['escalation_after_minutes'],
                        'is_active' => $p['is_active'],
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ]
                );

                $rule = [
                    'condition_type' => 'priority_exceeds',
                    'conditions' => ['priority' => $p['priority_level']],
                    'actions' => ['escalate_to' => 'supervisor', 'notify' => true],
                ];

                SlaRule::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'sla_policy_id' => $policy->id, 'name' => $p['name'] . ' - Escalation'],
                    array_merge($rule, [
                        'tenant_id' => $tenant->id,
                        'sla_policy_id' => $policy->id,
                        'name' => $p['name'] . ' - Escalation',
                        'condition_type' => $rule['condition_type'],
                        'conditions' => $rule['conditions'],
                        'actions' => $rule['actions'],
                        'is_active' => true,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );

                $created++;
                $ruleCount++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} SLA policies and {$ruleCount} rules seeded.");
        });

        $this->command->info('SlaPolicySeeder: complete.');
    }
}
