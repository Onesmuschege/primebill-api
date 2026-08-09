<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAllocation;
use App\Services\Billing\PaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentAllocationController extends Controller
{
    public function __construct(
        protected PaymentAllocationService $paymentAllocationService
    ) {}

    /**
     * GET /api/payment-allocations
     */
    public function index(Request $request): JsonResponse
    {
        $allocations = $this->paymentAllocationService->listAllocations($request);

        return response()->json([
            'success' => true,
            'data'    => $allocations,
        ]);
    }

    /**
     * POST /api/payment-allocations
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'payment_id'            => 'required|exists:payments,id',
            'client_id'             => 'required|exists:clients,id',
            'allocations'           => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|exists:invoices,id',
            'allocations.*.amount'  => 'required|numeric|min:0.01',
            'reference'             => 'nullable|string',
        ]);

        $allocations = $this->paymentAllocationService->allocate(
            array_merge($request->only(['payment_id', 'client_id', 'allocations', 'reference']), [
                'idempotency_key' => $request->header('Idempotency-Key'),
            ]),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment allocated successfully',
            'data'    => $allocations,
        ], 201);
    }

    /**
     * GET /api/payment-allocations/{allocation}
     */
    public function show(PaymentAllocation $allocation): JsonResponse
    {
        $allocation->load('payment', 'invoice', 'client');

        return response()->json([
            'success' => true,
            'data'    => $allocation,
        ]);
    }

    /**
     * POST /api/payment-allocations/{allocation}/reverse
     */
    public function reverse(Request $request, PaymentAllocation $allocation): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string',
        ]);

        $allocation = $this->paymentAllocationService->reverse(
            $allocation,
            $request->user()->id,
            $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment allocation reversed successfully',
            'data'    => $allocation,
        ]);
    }
}

