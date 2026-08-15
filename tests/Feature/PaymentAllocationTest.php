<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\LedgerService;
use App\Services\Billing\PaymentAllocationService;
use App\Services\Billing\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Client $client;
    protected PaymentAllocationService $service;
    protected InvoiceService $invoiceService;
    protected PaymentService $paymentService;
    protected LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');

        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->service = app(PaymentAllocationService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->paymentService = app(PaymentService::class);
        $this->ledgerService = app(LedgerService::class);
    }

    private function makeInvoices(int $count = 2, float $amount = 1000): array
    {
        $invoices = [];
        for ($i = 0; $i < $count; $i++) {
            $invoices[] = $this->invoiceService->createInvoice([
                'client_id' => $this->client->id,
                'amount'    => $amount,
                'due_date'  => now()->addDays(7)->toDateString(),
            ], $this->user->id);
        }
        return $invoices;
    }

    private function makePayment(int $amount = 2000): Payment
    {
        return $this->paymentService->recordPayment([
            'client_id'  => $this->client->id,
            'amount'     => $amount,
            'method'     => 'cash',
            'reference'  => 'REF-' . uniqid(),
        ], $this->user->id);
    }

    #[Test]
    public function payment_can_be_allocated_across_multiple_invoices(): void
    {
        $invoices = $this->makeInvoices(2, 1000);
        $payment = $this->makePayment(1500);

        $allocations = $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
                ['invoice_id' => $invoices[1]->id, 'amount' => 500],
            ],
        ], $this->user->id);

        $this->assertCount(2, $allocations);
        $this->assertEquals(2, PaymentAllocation::where('status', 'allocated')->count());

        // Allocation totals are tracked.
        $this->assertEquals(1000, $this->service->invoiceAllocatedTotal($invoices[0]));
        $this->assertEquals(500, $this->service->invoiceAllocatedTotal($invoices[1]));
        $this->assertEquals(1500, $this->service->paymentAllocatedTotal($payment));

        // Ledger stays balanced.
        $this->assertTrue($this->ledgerService->isBalanced());
    }

    #[Test]
    public function allocation_cannot_exceed_payment_amount(): void
    {
        $invoices = $this->makeInvoices(1, 1000);
        $payment = $this->makePayment(500);

        $this->expectException(\RuntimeException::class);

        $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
        ], $this->user->id);
    }

    #[Test]
    public function allocation_rejects_invoice_from_other_client(): void
    {
        $otherClient = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = $this->invoiceService->createInvoice([
            'client_id' => $otherClient->id,
            'amount'    => 1000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);
        $payment = $this->makePayment(500);

        $this->expectException(\RuntimeException::class);

        $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 500],
            ],
        ], $this->user->id);
    }

    #[Test]
    public function allocation_is_idempotent(): void
    {
        $invoices = $this->makeInvoices(1, 1000);
        $payment = $this->makePayment(1000);
        $key = 'alloc-' . uniqid();

        $data = [
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
            'idempotency_key' => $key,
        ];

        $first = $this->service->allocate($data, $this->user->id);
        $second = $this->service->allocate($data, $this->user->id);

        // Same allocation created once.
        $this->assertEquals($first[0]->id, $second[0]->id);
        $this->assertEquals(1, PaymentAllocation::count());
    }

    #[Test]
    public function cannot_allocate_a_non_completed_payment(): void
    {
        $invoices = $this->makeInvoices(1, 1000);

        $payment = Payment::factory()->pending()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'amount'    => 1000,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
        ], $this->user->id);
    }

    #[Test]
    public function allocation_can_be_reversed_and_ledger_stays_balanced(): void
    {
        $invoices = $this->makeInvoices(1, 1000);
        $payment = $this->makePayment(1000);

        $allocations = $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
        ], $this->user->id);

        $allocation = $allocations[0];

        $this->service->reverse($allocation, $this->user->id, 'Test reversal');

        $fresh = $allocation->fresh();
        $this->assertEquals('reversed', $fresh->status);
        $this->assertNotNull($fresh->reversed_at);
        $this->assertTrue($fresh->isReversed());

        $this->assertEquals(0, $this->service->invoiceAllocatedTotal($invoices[0]));
        $this->assertTrue($this->ledgerService->isBalanced());
    }

    #[Test]
    public function reversing_an_already_reversed_allocation_throws(): void
    {
        $invoices = $this->makeInvoices(1, 1000);
        $payment = $this->makePayment(1000);

        $allocations = $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
        ], $this->user->id);

        $allocation = $allocations[0];
        $this->service->reverse($allocation, $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->service->reverse($allocation->fresh(), $this->user->id);
    }

    #[Test]
    public function authenticated_user_can_create_allocation_via_api(): void
    {
        Sanctum::actingAs($this->user);

        $invoices = $this->makeInvoices(1, 1000);
        $payment = $this->makePayment(1000);

        $response = $this->postJson('/api/payment-allocations', [
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function unauthenticated_user_cannot_access_payment_allocations(): void
    {
        $response = $this->getJson('/api/payment-allocations');

        $response->assertStatus(401);
    }

    #[Test]
    public function tenant_isolation_blocks_cross_tenant_allocation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);

        // Payment belongs to the other tenant's client through a different
        // tenant customer. Attempting to allocate it as this tenant's client.
        $payment = Payment::factory()->completed()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'amount'    => 1000,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => 1, 'amount' => 500],
            ],
        ], $this->user->id);
    }

    #[Test]
    public function allocation_records_reflective_ledger_entries(): void
    {
        $invoices = $this->makeInvoices(1, 1000);
        $payment = $this->makePayment(1000);

        $this->service->allocate([
            'payment_id' => $payment->id,
            'client_id'  => $this->client->id,
            'allocations' => [
                ['invoice_id' => $invoices[0]->id, 'amount' => 1000],
            ],
        ], $this->user->id);

        // Allocation should have created a balanced debit/credit pair.
        $allocationEntries = LedgerEntry::where('entry_type', 'allocation_debit')->count();
        $this->assertEquals(1, $allocationEntries);
        $this->assertTrue($this->ledgerService->isBalanced());
    }
}

