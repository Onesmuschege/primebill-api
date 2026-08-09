<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds per-tenant settings. Settings carry tenant_id (unique per
 * tenant_id + key), so each tenant gets its own company/billing/sms config.
 */
class SettingsSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $settings = [
                // Company
                ['key' => 'company_name',    'value' => $tenant->name,              'group' => 'company'],
                ['key' => 'company_phone',   'value' => $tenant->contact_phone,     'group' => 'company'],
                ['key' => 'company_email',   'value' => $tenant->contact_email,     'group' => 'company'],
                ['key' => 'company_address', 'value' => $tenant->address,           'group' => 'company'],
                ['key' => 'company_paybill', 'value' => '400200',                   'group' => 'company'],

                // Billing
                ['key' => 'invoice_prefix',  'value' => 'INV',                     'group' => 'billing'],
                ['key' => 'tax_rate',        'value' => (string) $tenant->tax_rate, 'group' => 'billing'],
                ['key' => 'grace_period',    'value' => '3',                        'group' => 'billing'],
                ['key' => 'auto_suspend',    'value' => 'true',                     'group' => 'billing'],
                ['key' => 'auto_invoice',    'value' => 'true',                     'group' => 'billing'],
                ['key' => 'currency',        'value' => $tenant->currency,          'group' => 'billing'],

                // SMS
                ['key' => 'sms_gateway',     'value' => 'africas_talking',          'group' => 'sms'],
                ['key' => 'sms_api_key',     'value' => '',                         'group' => 'sms'],
                ['key' => 'sms_sender_id',   'value' => 'PRIMEBILL',                'group' => 'sms'],

                // Mpesa
                ['key' => 'mpesa_env',            'value' => 'sandbox',             'group' => 'mpesa'],
                ['key' => 'mpesa_consumer_key',   'value' => '',                    'group' => 'mpesa'],
                ['key' => 'mpesa_consumer_secret','value' => '',                    'group' => 'mpesa'],
                ['key' => 'mpesa_shortcode',      'value' => '',                    'group' => 'mpesa'],
                ['key' => 'mpesa_passkey',        'value' => '',                    'group' => 'mpesa'],

                // System
                ['key' => 'timezone',        'value' => $tenant->timezone,          'group' => 'system'],
                ['key' => 'date_format',     'value' => 'd/m/Y',                    'group' => 'system'],
            ];

            foreach ($settings as $setting) {
                Setting::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => $setting['key']],
                    $setting
                );
            }
        });

        $this->command->info('SettingsSeeder: per-tenant settings seeded.');
    }
}
