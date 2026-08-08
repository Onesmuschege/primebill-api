<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Models\Tenant;
use App\Services\Billing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_auto_applies_tax_from_tax_rates(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($tenant);

        TaxRate::create([
            'tenant_id'  => $tenant->id,
            'name'       => 'VAT',
            'code'       => 'VAT',
            'rate'       => 16,
            'type'       => 'percentage',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $service = app(InvoiceService::class);

        $this->assertEquals(160.0, $service->calculateTax(1000));
    }
}
