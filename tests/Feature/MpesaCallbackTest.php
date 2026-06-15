<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MpesaCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_stk_callback_records_payment_and_marks_transaction_completed(): void
    {
        $client = Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'phone' => '254700000001',
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TEST-001',
            'amount' => 1000,
            'tax' => 0,
            'total' => 1000,
            'status' => 'unpaid',
        ]);

        $tx = MpesaTransaction::create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'phone' => '254700000000',
            'amount' => 1000,
            'checkout_request_id' => 'ABC123',
            'status' => 'pending',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'M123',
                    'CheckoutRequestID' => 'ABC123',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA123'],
                            ['Name' => 'PhoneNumber', 'Value' => '254700000000'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/mpesa/stk-callback', $payload)
            ->assertStatus(200)
            ->assertJson(['ResultCode' => 0]);

        $tx->refresh();
        $this->assertEquals('completed', $tx->status);

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(1000, (float) $payment->amount);
        $this->assertEquals('mpesa', $payment->method);
    }
}
