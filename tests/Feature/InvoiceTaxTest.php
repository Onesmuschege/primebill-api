<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Billing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_auto_applies_tax_from_settings(): void
    {
        Setting::create(['key' => 'tax_rate', 'value' => '16', 'group' => 'billing']);

        $service = app(InvoiceService::class);

        $this->assertEquals(160.0, $service->calculateTax(1000));
    }
}
