<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\DebitNoteService;
use App\Services\Billing\DiscountService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\LedgerService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\RefundService;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedBillingTest extends TestCase
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
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ledger balance invariance
    // ─────────────────────────────────────────────────────────────────────

    public function test_ledger_balance_invariance_sum_debits_equals_sum_credits(): void
    {
        $ledger = app(LedgerService::class);
        $invoiceService = app(InvoiceService::class);

        // Create 3 invoices
        for ($i = 0; $i < 3; $i++) {
            $invoiceService->createInvoice([
                'client_id' => $this->client->id,
                'amount'    => 1000 + ($i * 500),
                'due_date'  => now()->addDays(7)->toDateString(),
            ], $this->user->id);
        }

        $this->assertTrue($ledger->isBalanced());

        $debits = (float) LedgerEntry::where('direction', 'debit')->sum('amount');
        $credits = (float) LedgerEntry::where('direction', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
    }

    public function test_ledger_balance_invariance_with_payments_and_reversals(): void
    {
        $ledger = app(LedgerService::class);
        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 2000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $payment = $paymentService->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 2000,
            'method'     => 'cash',
            'reference'  => 'REF-' . uniqid(),
        ], $this->user->id);

        $this->assertTrue($ledger->isBalanced());

        // Delete the payment -> reversal pair
        $paymentService->deletePayment($payment, $this->user->id);

        $this->assertTrue($ledger->isBalanced());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Idempotent refunds
    // ─────────────────────────────────────────────────────────────────────

    public function test_refund_is_idempotent(): void
    {
        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);
        $refundService = app(RefundService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 5000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $payment = $paymentService->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 5000,
            'method'     => 'mpesa',
            'mpesa_code' => 'MPESA' . uniqid(),
        ], $this->user->id);

        $key = 'refund-key-' . uniqid();

        $refund1 = $refundService->issue([
            'client_id'       => $this->client->id,
            'payment_id'      => $payment->id,
            'amount'          => 2000,
            'reason'          => 'Test refund',
            'idempotency_key' => $key,
        ], $this->user->id);

        $refund2 = $refundService->issue([
            'client_id'       => $this->client->id,
            'payment_id'      => $payment->id,
            'amount'          => 2000,
            'reason'          => 'Test refund',
            'idempotency_key' => $key,
        ], $this->user->id);

        $this->assertEquals($refund1->id, $refund2->id);

        // Only one refund record and one balanced ledger pair
        $this->assertEquals(1, Refund::count());
        $this->assertEquals(1, LedgerEntry::where('entry_type', 'refund_issued')->count());
        $this->assertEquals(2, LedgerEntry::where('payment_id', $payment->id)
            ->whereIn('entry_type', ['refund_issued', 'cash_credit'])
            ->count());
    }

    public function test_refund_cannot_exceed_payment_amount(): void
    {
        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);
        $refundService = app(RefundService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 3000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $payment = $paymentService->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 3000,
            'method'     => 'cash',
            'reference'  => 'REF-' . uniqid(),
        ], $this->user->id);

        $this->expectException(\RuntimeException::class);

        $refundService->issue([
            'client_id'  => $this->client->id,
            'payment_id' => $payment->id,
            'amount'     => 5000, // exceeds payment amount
        ], $this->user->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // No duplicate ledger on invoice + payment
    // ─────────────────────────────────────────────────────────────────────

    public function test_no_duplicate_ledger_entries_on_invoice_and_payment(): void
    {
        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 1500,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        // Invoice should have exactly 2 ledger entries (debit + credit)
        $this->assertEquals(2, LedgerEntry::where('invoice_id', $invoice->id)->count());

        $payment = $paymentService->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 1500,
            'method'     => 'cash',
            'reference'  => 'REF-' . uniqid(),
        ], $this->user->id);

        // Payment should add exactly 2 more ledger entries (debit + credit)
        $this->assertEquals(4, LedgerEntry::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(2, LedgerEntry::where('payment_id', $payment->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Wallet
    // ─────────────────────────────────────────────────────────────────────

    public function test_wallet_deposit_and_withdrawal(): void
    {
        $walletService = app(WalletService::class);

        $deposit = $walletService->deposit($this->client->id, 1000, $this->user->id, 'Test deposit');

        $this->assertEquals(1000, $walletService->getBalance($this->client->id));

        $withdrawal = $walletService->withdraw($this->client->id, 400, $this->user->id, 'Test withdrawal');

        $this->assertEquals(600, $walletService->getBalance($this->client->id));
    }

    public function test_wallet_insufficient_balance_throws(): void
    {
        $walletService = app(WalletService::class);

        $walletService->deposit($this->client->id, 100, $this->user->id);

        $this->expectException(\RuntimeException::class);

        $walletService->withdraw($this->client->id, 500, $this->user->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Credit / Debit notes
    // ─────────────────────────────────────────────────────────────────────

    public function test_credit_note_issue_and_reverse(): void
    {
        $invoiceService = app(InvoiceService::class);
        $creditNoteService = app(CreditNoteService::class);
        $ledger = app(LedgerService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 2000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $creditNote = $creditNoteService->issue([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 500,
            'reason'     => 'Service credit',
        ], $this->user->id);

        $this->assertEquals('issued', $creditNote->status);
        $this->assertTrue($ledger->isBalanced());

        $creditNoteService->reverse($creditNote, $this->user->id, 'Test reversal');

        $this->assertEquals('reversed', $creditNote->fresh()->status);
        $this->assertTrue($ledger->isBalanced());
    }

    public function test_debit_note_issue_and_reverse(): void
    {
        $invoiceService = app(InvoiceService::class);
        $debitNoteService = app(DebitNoteService::class);
        $ledger = app(LedgerService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 2000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        $debitNote = $debitNoteService->issue([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 300,
            'reason'     => 'Late fee',
        ], $this->user->id);

        $this->assertEquals('issued', $debitNote->status);
        $this->assertTrue($ledger->isBalanced());

        $debitNoteService->reverse($debitNote, $this->user->id, 'Test reversal');

        $this->assertEquals('reversed', $debitNote->fresh()->status);
        $this->assertTrue($ledger->isBalanced());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tax engine
    // ─────────────────────────────────────────────────────────────────────

    public function test_tax_engine_multiple_rates(): void
    {
        TaxRate::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'VAT',
            'code'       => 'VAT',
            'rate'       => 16,
            'type'       => 'percentage',
            'is_active'  => true,
            'is_default' => true,
        ]);

        TaxRate::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Service Levy',
            'code'      => 'SL',
            'rate'      => 2,
            'type'      => 'percentage',
            'is_active' => true,
        ]);

        $invoiceService = app(InvoiceService::class);

        $invoice = $invoiceService->createInvoice([
            'client_id' => $this->client->id,
            'amount'    => 1000,
            'due_date'  => now()->addDays(7)->toDateString(),
        ], $this->user->id);

        // 16% VAT + 2% levy = 18% of 1000 = 180
        $this->assertEquals(180, (float) $invoice->tax);
        $this->assertEquals(1180, (float) $invoice->total);
        $this->assertEquals(2, $invoice->taxLines()->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Coupon validation
    // ─────────────────────────────────────────────────────────────────────

    public function test_coupon_validation_and_application(): void
    {
        $discountService = app(DiscountService::class);
        $invoiceService = app(InvoiceService::class);

        $coupon = Coupon::create([
            'tenant_id'    => $this->tenant->id,
            'code'         => 'SAVE10',
            'type'         => 'percentage',
            'value'        => 10,
            'min_subtotal' => 500,
            'usage_limit'  => 5,
            'is_active'    => true,
        ]);

        $invoice = $invoiceService->createInvoice([
            'client_id'   => $this->client->id,
            'amount'      => 1000,
            'due_date'    => now()->addDays(7)->toDateString(),
            'coupon_code' => 'SAVE10',
        ], $this->user->id);

        $this->assertEquals(100, (float) $invoice->discount);
        $this->assertEquals(900, (float) $invoice->subtotal);
        $this->assertEquals(1, $coupon->fresh()->usage_count);
    }

    public function test_coupon_expired_is_rejected(): void
    {
        $discountService = app(DiscountService::class);

        $coupon = Coupon::create([
            'tenant_id'  => $this->tenant->id,
            'code'       => 'EXPIRED',
            'type'       => 'percentage',
            'value'      => 10,
            'is_active'  => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->expectException(\RuntimeException::class);

        $discountService->validateCoupon('EXPIRED', $this->client->id, 1000);
    }

    public function test_coupon_usage_limit_reached_is_rejected(): void
    {
        $discountService = app(DiscountService::class);

        $coupon = Coupon::create([
            'tenant_id'    => $this->tenant->id,
            'code'         => 'LIMITED',
            'type'         => 'percentage',
            'value'        => 10,
            'usage_limit'  => 1,
            'usage_count'  => 1,
            'is_active'    => true,
        ]);

        $this->expectException(\RuntimeException::class);

        $discountService->validateCoupon('LIMITED', $this->client->id, 1000);
    }
}
