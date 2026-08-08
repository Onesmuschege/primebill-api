<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);
        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /** @test */
    public function duplicate_mpesa_callback_creates_single_payment(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-IDEMP-001',
            'amount' => 1500,
            'tax' => 0,
            'total' => 1500,
            'status' => 'unpaid',
            'due_date' => now()->addDays(30),
        ]);

        // Create the M-Pesa transaction record first (simulating STK push)
        $mpesaTx = \App\Models\MpesaTransaction::create([
            'client_id' => $this->client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000001',
            'amount' => 1500,
            'checkout_request_id' => 'CR-IDEM-1',
            'status' => 'pending',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M-IDEM-1',
                    'CheckoutRequestID' => 'CR-IDEM-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-IDEM-1'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000001'],
                        ],
                    ],
                ],
            ],
        ];

        // First callback
        $this->postJson('/api/mpesa/stk-callback', $payload);

        // Duplicate callback (same CheckoutRequestID)
        $this->postJson('/api/mpesa/stk-callback', $payload);

        // Third callback
        $this->postJson('/api/mpesa/stk-callback', $payload);

        $paymentCount = Payment::where('invoice_id', $invoice->id)->count();
        $this->assertEquals(1, $paymentCount, 'Duplicate M-Pesa callbacks must not create multiple payments');

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals(1500, (float) $payment->amount);
    }

    /** @test */
    public function duplicate_payment_reference_is_deduplicated(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-REF-001',
            'amount' => 2000,
            'tax' => 0,
            'total' => 2000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(30),
        ]);

        \App\Models\MpesaTransaction::create([
            'client_id' => $this->client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000001',
            'amount' => 2000,
            'checkout_request_id' => 'CR-REF-123',
            'status' => 'pending',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M-REF-1',
                    'CheckoutRequestID' => 'CR-REF-123',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 2000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-REF-123'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000001'],
                        ],
                    ],
                ],
            ],
        ];

        // First callback
        $this->postJson('/api/mpesa/stk-callback', $payload);

        // Duplicate callback - should be idempotent
        $this->postJson('/api/mpesa/stk-callback', $payload);

        $payments = Payment::where('invoice_id', $invoice->id)->get();
        $this->assertLessThanOrEqual(1, $payments->count(), 'Duplicate callbacks must not create multiple payments');
    }

    /** @test */
    public function partial_payment_then_full_payment_updates_invoice_correctly(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-PARTIAL-001',
            'amount' => 3000,
            'tax' => 0,
            'total' => 3000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(30),
        ]);

        // Partial payment
        \App\Models\MpesaTransaction::create([
            'client_id' => $this->client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000001',
            'amount' => 1500,
            'checkout_request_id' => 'CR-PART-1',
            'status' => 'pending',
        ]);

        $partialPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M-PART-1',
                    'CheckoutRequestID' => 'CR-PART-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-PART-1'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000001'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/mpesa/stk-callback', $partialPayload);
        $invoice->refresh();
        $this->assertEquals('partial', $invoice->status);

        // Remaining payment
        \App\Models\MpesaTransaction::create([
            'client_id' => $this->client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000001',
            'amount' => 1500,
            'checkout_request_id' => 'CR-PART-2',
            'status' => 'pending',
        ]);

        $remainingPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M-PART-2',
                    'CheckoutRequestID' => 'CR-PART-2',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-PART-2'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000001'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/mpesa/stk-callback', $remainingPayload);
        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    /** @test */
    public function overpayment_does_not_corrupt_invoice_state(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-OVER-001',
            'amount' => 1000,
            'tax' => 0,
            'total' => 1000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(30),
        ]);

        \App\Models\MpesaTransaction::create([
            'client_id' => $this->client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000001',
            'amount' => 5000,
            'checkout_request_id' => 'CR-OVER-1',
            'status' => 'pending',
        ]);

        $overPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M-OVER-1',
                    'CheckoutRequestID' => 'CR-OVER-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 5000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-OVER-1'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000001'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/mpesa/stk-callback', $overPayload);
        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
    }

    /** @test */
    public function payment_triggers_account_extension(): void
    {
        $plan = \App\Models\Plan::factory()->create([
            'tenant_id' => $this->tenant->id,
            'validity_days' => 30,
        ]);

        $account = \App\Models\ClientAccount::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $plan->id,
            'username' => 'extend01',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'expiry_date' => now()->subDays(1),
            'service_state' => \App\Models\ClientAccount::STATE_ACTIVE,
        ]);

        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-EXTEND-001',
            'amount' => 1000,
            'tax' => 0,
            'total' => 1000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(30),
        ]);

        \App\Models\MpesaTransaction::create([
            'client_id' => $this->client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000001',
            'amount' => 1000,
            'checkout_request_id' => 'CR-EXT-1',
            'status' => 'pending',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M-EXT-1',
                    'CheckoutRequestID' => 'CR-EXT-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-EXT-1'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000001'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/mpesa/stk-callback', $payload);
        $account->refresh();

        $this->assertTrue($account->expiry_date->isFuture(), 'Payment should extend account expiry');
    }
}
