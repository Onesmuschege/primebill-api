<?php

namespace Database\Seeders;

use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds report schedules (and sample deliveries) referencing saved reports.
 * Idempotent on tenant + name.
 */
class ReportScheduleSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $reports = SavedReport::where('tenant_id', $tenant->id)->get();

            if ($reports->isEmpty()) {
                $this->command->warn("ReportScheduleSeeder [{$tenant->slug}]: No saved reports found. Skipping.");
                return;
            }

            $schedules = [
                ['name' => 'Monthly Revenue Digest', 'frequency' => 'monthly', 'format' => 'pdf'],
                ['name' => 'Weekly Customer Snapshot', 'frequency' => 'weekly', 'format' => 'csv'],
                ['name' => 'Daily Network Alerts', 'frequency' => 'daily', 'format' => 'email'],
            ];

            $created = 0;
            $deliveries = 0;

            foreach ($schedules as $index => $s) {
                $report = $reports[$index % $reports->count()];
                $schedule = ReportSchedule::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $s['name']],
                    [
                        'tenant_id' => $tenant->id,
                        'saved_report_id' => $report->id,
                        'name' => $s['name'],
                        'frequency' => $s['frequency'],
                        'format' => $s['format'],
                        'recipients' => ['emails' => [$admin?->email ?? $tenant->contact_email], 'users' => [$admin?->id]],
                        'last_sent_at' => $index === 0 ? Carbon::now()->subDays(5) : null,
                        'next_send_at' => Carbon::now()->addDays(7 - $index * 2),
                        'is_active' => $index !== 2,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ]
                );

                if ($schedule->wasRecentlyCreated && $index === 0) {
                    ReportDelivery::create([
                        'tenant_id' => $tenant->id,
                        'report_schedule_id' => $schedule->id,
                        'saved_report_id' => $report->id,
                        'status' => 'completed',
                        'format' => $s['format'],
                        'file_path' => 'reports/' . $tenant->slug . '/monthly-revenue.pdf',
                        'file_name' => 'monthly-revenue.pdf',
                        'file_size' => 245000,
                        'recipients' => ['emails' => [$admin?->email]],
                        'processed_at' => Carbon::now()->subDays(5)->addMinutes(2),
                        'sent_at' => Carbon::now()->subDays(5)->addMinutes(3),
                        'metadata' => ['seed' => true],
                        'created_by' => $admin?->id,
                    ]);
                    $deliveries++;
                }

                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} report schedules and {$deliveries} deliveries seeded.");
        });

        $this->command->info('ReportScheduleSeeder: complete.');
    }
}
