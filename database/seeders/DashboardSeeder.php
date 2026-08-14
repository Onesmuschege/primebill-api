<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds tenant dashboards and their widgets. Idempotent on tenant + code.
 */
class DashboardSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $dashboards = [
                [
                    'code' => 'executive', 'name' => 'Executive Overview', 'type' => 'tenant',
                    'is_default' => true, 'is_public' => true, 'sort_order' => 1,
                    'widgets' => [
                        ['code' => 'revenue', 'name' => 'Revenue', 'type' => 'metric', 'chart_type' => 'number', 'data_source' => 'invoices', 'query' => ['sum' => 'total'], 'options' => ['format' => 'KES'], 'sort_order' => 1, 'refresh_interval' => 300],
                        ['code' => 'active_clients', 'name' => 'Active Clients', 'type' => 'metric', 'chart_type' => 'number', 'data_source' => 'clients', 'query' => ['count' => 'status', 'where' => 'active'], 'options' => [], 'sort_order' => 2, 'refresh_interval' => 300],
                        ['code' => 'revenue_chart', 'name' => 'Revenue Trend', 'type' => 'chart', 'chart_type' => 'line', 'data_source' => 'payments', 'query' => ['group_by' => 'month'], 'options' => [], 'sort_order' => 3, 'refresh_interval' => 600],
                    ],
                ],
                [
                    'code' => 'network', 'name' => 'Network Operations', 'type' => 'team',
                    'is_default' => false, 'is_public' => false, 'sort_order' => 2,
                    'widgets' => [
                        ['code' => 'router_status', 'name' => 'Router Status', 'type' => 'metric', 'chart_type' => 'table', 'data_source' => 'routers', 'query' => ['status' => 'all'], 'options' => [], 'sort_order' => 1, 'refresh_interval' => 60],
                        ['code' => 'alerts', 'name' => 'Active Alerts', 'type' => 'chart', 'chart_type' => 'bar', 'data_source' => 'alerts', 'query' => ['status' => 'open'], 'options' => [], 'sort_order' => 2, 'refresh_interval' => 120],
                    ],
                ],
                [
                    'code' => 'finance', 'name' => 'Finance', 'type' => 'team',
                    'is_default' => false, 'is_public' => false, 'sort_order' => 3,
                    'widgets' => [
                        ['code' => 'outstanding', 'name' => 'Outstanding Balance', 'type' => 'metric', 'chart_type' => 'number', 'data_source' => 'invoices', 'query' => ['sum' => 'balance'], 'options' => ['format' => 'KES'], 'sort_order' => 1, 'refresh_interval' => 600],
                        ['code' => 'collections', 'name' => 'Collections', 'type' => 'chart', 'chart_type' => 'pie', 'data_source' => 'dunning', 'query' => ['group_by' => 'status'], 'options' => [], 'sort_order' => 2, 'refresh_interval' => 600],
                    ],
                ],
            ];

            $created = 0;
            $widgets = 0;

            foreach ($dashboards as $d) {
                $widgetsData = $d['widgets'];
                unset($d['widgets']);

                $dash = Dashboard::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $d['code']],
                    array_merge($d, [
                        'tenant_id' => $tenant->id,
                        'layout' => ['columns' => 12, 'rows' => 4],
                        'filters' => ['date_range' => 'this_month'],
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;

                foreach ($widgetsData as $w) {
                    DashboardWidget::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'dashboard_id' => $dash->id, 'code' => $w['code']],
                        array_merge($w, [
                            'tenant_id' => $tenant->id,
                            'dashboard_id' => $dash->id,
                            'layout' => ['w' => 4, 'h' => 3],
                            'created_by' => $admin?->id,
                            'updated_by' => $admin?->id,
                        ])
                    );
                    $widgets++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} dashboards and {$widgets} widgets seeded.");
        });

        $this->command->info('DashboardSeeder: complete.');
    }
}
