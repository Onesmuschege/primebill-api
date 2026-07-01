<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Company
            ['key' => 'company_name',    'value' => 'PrimeBill ISP',         'group' => 'company'],
            ['key' => 'company_phone',   'value' => '+254700000000',          'group' => 'company'],
            ['key' => 'company_email',   'value' => 'info@primebill.co.ke',   'group' => 'company'],
            ['key' => 'company_address', 'value' => 'Nairobi, Kenya',         'group' => 'company'],
            ['key' => 'company_paybill', 'value' => '000000',                 'group' => 'company'],

            // Billing
            ['key' => 'invoice_prefix',  'value' => 'INV',                   'group' => 'billing'],
            ['key' => 'tax_rate',        'value' => '0',                      'group' => 'billing'],
            ['key' => 'grace_period',    'value' => '3',                      'group' => 'billing'],
            ['key' => 'auto_suspend',    'value' => 'true',                   'group' => 'billing'],
            ['key' => 'auto_invoice',    'value' => 'true',                   'group' => 'billing'],
            ['key' => 'currency',        'value' => 'KES',                    'group' => 'billing'],

            // SMS
            ['key' => 'sms_gateway',     'value' => 'africas_talking',        'group' => 'sms'],
            ['key' => 'sms_api_key',     'value' => '',                       'group' => 'sms'],
            ['key' => 'sms_sender_id',   'value' => 'PRIMEBILL',              'group' => 'sms'],

            // Mpesa
            ['key' => 'mpesa_env',           'value' => 'sandbox',            'group' => 'mpesa'],
            ['key' => 'mpesa_consumer_key',  'value' => '',                   'group' => 'mpesa'],
            ['key' => 'mpesa_consumer_secret','value' => '',                  'group' => 'mpesa'],
            ['key' => 'mpesa_shortcode',     'value' => '',                   'group' => 'mpesa'],
            ['key' => 'mpesa_passkey',       'value' => '',                   'group' => 'mpesa'],

            // System
            ['key' => 'timezone',        'value' => 'Africa/Nairobi',         'group' => 'system'],
            ['key' => 'date_format',     'value' => 'd/m/Y',                  'group' => 'system'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}