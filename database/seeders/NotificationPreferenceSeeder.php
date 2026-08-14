<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds notification preferences for clients and staff users. Morphable
 * notifiable. Idempotent via the tenant/type/id unique constraint.
 */
class NotificationPreferenceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $created = 0;

            // Staff users (all users).
            foreach (User::where('tenant_id', $tenant->id)->get() as $u) {
                NotificationPreference::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'notifiable_type' => 'user', 'notifiable_id' => $u->id],
                    [
                        'tenant_id' => $tenant->id,
                        'notifiable_type' => 'user',
                        'notifiable_id' => $u->id,
                        'email_enabled' => ['invoice' => true, 'payment' => true, 'ticket' => true, 'security' => true],
                        'sms_enabled' => ['ticket' => true, 'alert' => true],
                        'whatsapp_enabled' => ['ticket' => false],
                        'push_enabled' => ['ticket' => true, 'alert' => true],
                        'in_app_enabled' => ['invoice' => true, 'payment' => true, 'ticket' => true, 'alert' => true],
                        'quiet_hours' => ['start' => '22:00', 'end' => '08:00', 'timezone' => 'Africa/Nairobi'],
                        'metadata' => ['seed' => 'pref-user-' . $u->id],
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ]
                );
                $created++;
            }

            // A subset of clients.
            foreach (Client::where('tenant_id', $tenant->id)->where('status', 'active')->take(30)->get() as $c) {
                NotificationPreference::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'notifiable_type' => 'client', 'notifiable_id' => $c->id],
                    [
                        'tenant_id' => $tenant->id,
                        'notifiable_type' => 'client',
                        'notifiable_id' => $c->id,
                        'email_enabled' => ['invoice' => true, 'payment' => true, 'maintenance' => true],
                        'sms_enabled' => ['invoice' => true, 'outage' => true],
                        'whatsapp_enabled' => ['maintenance' => true],
                        'push_enabled' => [],
                        'in_app_enabled' => ['invoice' => true, 'payment' => true],
                        'quiet_hours' => ['start' => '21:00', 'end' => '08:00', 'timezone' => 'Africa/Nairobi'],
                        'metadata' => ['seed' => 'pref-client-' . $c->id],
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} notification preferences seeded.");
        });

        $this->command->info('NotificationPreferenceSeeder: complete.');
    }
}
