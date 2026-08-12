<?php

namespace Database\Seeders;

use App\Models\DunningStep;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class CollectionsSeeder extends Seeder
{
    use SeedsForTenant;

    /**
     * Default dunning escalation steps, idempotent per tenant.
     *
     * Due -> Reminder (email, +3) -> Reminder (sms, +7) -> Warning (email, +10)
     * -> Warning (sms, +14) -> Soft suspension (suspend, +21) -> Collections (escalate, +30)
     */
    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $defaultSteps = [
                ['name' => 'Due Reminder Email', 'sequence' => 1, 'action' => 'email',  'days_after_due' => 3,  'template' => 'Dear {name}, your invoice {invoice_number} of KES {amount} is due. Please pay to avoid interruption.'],
                ['name' => 'Due Reminder SMS',   'sequence' => 2, 'action' => 'sms',    'days_after_due' => 7,  'template' => 'Dear {name}, invoice {invoice_number} of KES {amount} is overdue. Pay to avoid interruption.'],
                ['name' => 'Warning Email',      'sequence' => 3, 'action' => 'email',  'days_after_due' => 10, 'template' => 'Dear {name}, invoice {invoice_number} of KES {amount} is overdue ({invoice_status}). Your service may be suspended soon.'],
                ['name' => 'Warning SMS',        'sequence' => 4, 'action' => 'sms',    'days_after_due' => 14, 'template' => 'Dear {name}, invoice {invoice_number} of KES {amount} is overdue. Service will be suspended if unpaid.'],
                ['name' => 'Suspend Service',    'sequence' => 5, 'action' => 'suspend', 'days_after_due' => 21, 'template' => null],
                ['name' => 'Escalate Collections', 'sequence' => 6, 'action' => 'escalate', 'days_after_due' => 30, 'template' => null],
            ];

            foreach ($defaultSteps as $step) {
                DunningStep::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $step['name']],
                    $step
                );
            }

            $this->command->line("  [{$tenant->slug}] Dunning steps seeded.");
        });

        $this->command->info('CollectionsSeeder: complete.');
    }
}
