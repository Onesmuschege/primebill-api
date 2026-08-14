<?php

namespace Database\Seeders;

use App\Models\SavedReport;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds saved reports per tenant. Idempotent on tenant + code.
 */
class SavedReportSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $reports = [
                [
                    'code' => 'revenue', 'name' => 'Monthly Revenue', 'type' => 'financial',
                    'description' => 'Aggregated revenue by month (invoices + payments).',
                    'filters' => ['date_range' => 'last_12_months'], 'columns' => ['month', 'invoices', 'payments'],
                    'grouping' => ['group_by' => 'month'], 'sorting' => ['sort_by' => 'month', 'direction' => 'desc'],
                    'visualization' => ['chart_type' => 'bar'], 'is_public' => true, 'is_favorite' => true,
                ],
                [
                    'code' => 'customers', 'name' => 'Customer Base', 'type' => 'customer',
                    'description' => 'Active customers by status and plan.',
                    'filters' => ['status' => 'active'], 'columns' => ['name', 'status', 'plan'],
                    'grouping' => ['group_by' => 'status'], 'sorting' => ['sort_by' => 'name'],
                    'visualization' => ['chart_type' => 'pie'], 'is_public' => true, 'is_favorite' => false,
                ],
                [
                    'code' => 'network', 'name' => 'Network Health', 'type' => 'network',
                    'description' => 'Router uptime, alerts and incidents summary.',
                    'filters' => [], 'columns' => ['router', 'status', 'alerts'],
                    'grouping' => [], 'sorting' => ['sort_by' => 'status'],
                    'visualization' => ['chart_type' => 'table'], 'is_public' => false, 'is_favorite' => true,
                ],
                [
                    'code' => 'inventory', 'name' => 'Inventory Levels', 'type' => 'operations',
                    'description' => 'Stock levels and low-stock items.',
                    'filters' => ['low_stock' => true], 'columns' => ['item', 'qty', 'status'],
                    'grouping' => ['group_by' => 'category'], 'sorting' => ['sort_by' => 'qty'],
                    'visualization' => ['chart_type' => 'bar'], 'is_public' => false, 'is_favorite' => false,
                ],
                [
                    'code' => 'sla', 'name' => 'SLA Compliance', 'type' => 'support',
                    'description' => 'Ticket SLA response and resolution compliance.',
                    'filters' => [], 'columns' => ['queue', 'breached', 'on_time'],
                    'grouping' => ['group_by' => 'queue'], 'sorting' => ['sort_by' => 'breached'],
                    'visualization' => ['chart_type' => 'line'], 'is_public' => false, 'is_favorite' => false,
                ],
            ];

            $created = 0;
            foreach ($reports as $r) {
                SavedReport::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $r['code']],
                    array_merge($r, [
                        'tenant_id' => $tenant->id,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} saved reports seeded.");
        });

        $this->command->info('SavedReportSeeder: complete.');
    }
}
