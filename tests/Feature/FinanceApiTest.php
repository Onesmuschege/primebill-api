<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');

        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->user);
    }

    #[Test]
    public function wallet_deposit_and_balance_via_api(): void
    {
        $this->postJson('/api/finance/wallet/deposit', [
            'client_id' => $this->client->id,
            'amount'    => 1000,
            'reference' => 'DEP-001',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->getJson('/api/finance/wallet/balance?client_id=' . $this->client->id)
            ->assertStatus(200)
            ->assertJsonPath('data.balance', 1000);
    }

    #[Test]
    public function wallet_withdrawal_via_api(): void
    {
        $this->postJson('/api/finance/wallet/deposit', [
            'client_id' => $this->client->id,
            'amount'    => 500,
        ])->assertStatus(201);

        $this->postJson('/api/finance/wallet/withdraw', [
            'client_id' => $this->client->id,
            'amount'    => 200,
        ])->assertStatus(200)->assertJsonPath('success', true);

        $this->getJson('/api/finance/wallet/balance?client_id=' . $this->client->id)
            ->assertStatus(200)
            ->assertJsonPath('data.balance', 300);
    }

    #[Test]
    public function unauthenticated_user_cannot_access_finance(): void
    {
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));

        $this->postJson('/api/finance/wallet/deposit', [
            'client_id' => $this->client->id,
            'amount'    => 100,
        ])->assertStatus(403);
    }

    #[Test]
    public function credit_note_issue_and_list_via_api(): void
    {
        $invoice = app(InvoiceService::class)->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 2000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $this->postJson('/api/finance/credit-notes', [
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 500,
            'reason'     => 'Service credit',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->getJson('/api/finance/credit-notes')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function debit_note_issue_and_list_via_api(): void
    {
        $invoice = app(InvoiceService::class)->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 2000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $this->postJson('/api/finance/debit-notes', [
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 300,
            'reason'     => 'Late fee',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->getJson('/api/finance/debit-notes')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function refund_issue_and_list_via_api(): void
    {
        $invoice = app(InvoiceService::class)->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 1000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $payment = app(PaymentService::class)->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 1000,
            'method'     => 'cash',
            'reference'  => 'REF-' . uniqid(),
        ], $this->user->id);

        $this->postJson('/api/finance/refunds', [
            'client_id'  => $this->client->id,
            'payment_id' => $payment->id,
            'amount'     => 300,
            'reason'     => 'Test refund',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->getJson('/api/finance/refunds')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function trial_balance_and_ledger_verify_via_api(): void
    {
        // Create an invoice to create ledger entries.
        app(InvoiceService::class)->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 1000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $this->getJson('/api/finance/statement/trial-balance')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balanced', true);

        $this->getJson('/api/finance/statement/verify-ledger')
            ->assertStatus(200)
            ->assertJsonPath('data.balanced', true);
    }

    #[Test]
    public function payment_plan_create_via_api(): void
    {
        $invoice = app(InvoiceService::class)->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 1200,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $this->postJson('/api/finance/payment-plans', [
            'client_id'         => $this->client->id,
            'invoice_id'        => $invoice->id,
            'installment_count' => 3,
            'frequency'         => 'monthly',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->getJson('/api/finance/payment-plans')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}

