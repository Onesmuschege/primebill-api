<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    // GET /api/payments
    public function index(Request $request)
    {
        $payments = $this->paymentService->getAllPayments($request);

        return response()->json([
            'success' => true,
            'data'    => $payments,
        ]);
    }

    // POST /api/payments
    public function store(StorePaymentRequest $request)
    {
        $payload = $request->validated();
        $payload['idempotency_key'] = $request->header('Idempotency-Key')
            ?: (!empty($payload['reference']) ? "{$payload['method']}:{$payload['reference']}" : null);

        $payment = $this->paymentService->recordPayment(
            $payload,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data'    => $payment,
        ], 201);
    }

    // GET /api/payments/{id}
    public function show(Payment $payment)
    {
        $payment->load('client', 'invoice');

        return response()->json([
            'success' => true,
            'data'    => $payment,
        ]);
    }

    // DELETE /api/payments/{id}
    public function destroy(Request $request, Payment $payment)
    {
        $this->paymentService->deletePayment(
            $payment,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment deleted successfully',
        ]);
    }

    // GET /api/payments/summary
    public function summary()
    {
        $summary = $this->paymentService->getDailySummary();

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ]);
    }

    // GET /api/payments/{id}/receipt
    public function receipt(Payment $payment, SettingsService $settings)
    {
        $payment->load('client', 'invoice');

        return response()->json([
            'success' => true,
            'data'    => [
                'receipt_number' => 'RCP-' . str_pad($payment->id, 8, '0', STR_PAD_LEFT),
                'payment'          => $payment,
                'company'          => [
                    'name'    => $settings->get('company_name'),
                    'phone'   => $settings->get('company_phone'),
                    'email'   => $settings->get('company_email'),
                    'address' => $settings->get('company_address'),
                ],
                'issued_at' => $payment->created_at?->toIso8601String(),
            ],
        ]);
    }
}
