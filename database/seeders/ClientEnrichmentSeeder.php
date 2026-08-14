<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCustomField;
use App\Models\ClientCustomFieldValue;
use App\Models\ClientNote;
use App\Models\ClientTag;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Enriches clients with notes, tags (incl. assignments), custom fields and
 * contacts. Idempotent via natural unique keys.
 */
class ClientEnrichmentSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $clients = Client::where('tenant_id', $tenant->id)->get();

            if ($clients->isEmpty() || ! $admin) {
                $this->command->warn("ClientEnrichmentSeeder [{$tenant->slug}]: No clients/admin found. Skipping.");
                return;
            }

            $tags = [
                ['name' => 'High Value', 'color' => '#f59e0b'],
                ['name' => 'New Connection', 'color' => '#10b981'],
                ['name' => 'VIP', 'color' => '#8b5cf6'],
                ['name' => 'Sensitive', 'color' => '#ef4444'],
                ['name' => 'Business', 'color' => '#2563eb'],
            ];
            $tagModels = [];
            foreach ($tags as $t) {
                $tag = ClientTag::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $t['name']],
                    array_merge($t, ['tenant_id' => $tenant->id, 'description' => $t['name'] . ' segment'])
                );
                $tagModels[] = $tag;
            }

            $fields = [
                ['name' => 'installation_date', 'label' => 'Installation Date', 'type' => 'date'],
                ['name' => 'preferred_contact', 'label' => 'Preferred Contact', 'type' => 'select', 'options' => ['SMS', 'Email', 'WhatsApp']],
                ['name' => 'notes_internal', 'label' => 'Internal Notes', 'type' => 'textarea'],
            ];
            $fieldModels = [];
            foreach ($fields as $f) {
                $field = ClientCustomField::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $f['name']],
                    array_merge($f, ['tenant_id' => $tenant->id, 'is_required' => false, 'is_visible_on_portal' => false, 'sort_order' => 0])
                );
                $fieldModels[] = $field;
            }

            $created = 0;
            $i = 0;
            foreach ($clients->take(25) as $client) {
                ClientNote::create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'created_by' => $admin->id,
                    'note' => 'Seeded client note: customer relationship entry for ' . $client->full_name,
                    'type' => ['general', 'call', 'support'][$i % 3],
                    'priority' => ['low', 'normal', 'high'][$i % 3],
                    'is_pinned' => $i % 9 === 0,
                    'pinned_at' => $i % 9 === 0 ? now() : null,
                    'created_at' => Carbon::now()->subDays($i % 20),
                    'updated_at' => Carbon::now()->subDays($i % 20),
                ]);
                $created++;

                $tag = $tagModels[$i % count($tagModels)];
                if (! $client->tags()->where('client_tags.id', $tag->id)->exists()) {
                    $client->tags()->attach($tag->id, ['tenant_id' => $tenant->id, 'assigned_by' => $admin->id]);
                    $created++;
                }

                $field = $fieldModels[$i % count($fieldModels)];
                $value = match ($field->type) {
                    'select' => $field->options[0],
                    'textarea' => 'Internal note for ' . $client->full_name,
                    default => now()->subDays(20)->toDateString(),
                };
                ClientCustomFieldValue::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'client_id' => $client->id, 'client_custom_field_id' => $field->id],
                    ['tenant_id' => $tenant->id, 'client_id' => $client->id, 'client_custom_field_id' => $field->id, 'value' => $value]
                );
                $created++;

                if ($i % 2 === 0) {
                    Contact::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'client_id' => $client->id, 'email' => 'contact.' . $client->email],
                        [
                            'tenant_id' => $tenant->id,
                            'client_id' => $client->id,
                            'first_name' => $client->first_name,
                            'last_name' => $client->last_name,
                            'email' => 'contact.' . $client->email,
                            'phone' => '0799' . str_pad((string) ($tenant->id * 1000 + $i), 6, '0', STR_PAD_LEFT),
                            'relationship' => 'self',
                            'type' => ['billing', 'technical'][$i % 2],
                            'is_primary' => true,
                            'notes' => 'Primary contact',
                        ]
                    );
                    $created++;
                }

                $i++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} client enrichment records seeded.");
        });

        $this->command->info('ClientEnrichmentSeeder: complete.');
    }
}
