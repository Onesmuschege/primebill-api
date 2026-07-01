<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\Billing\VoucherService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    protected VoucherService $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    // GET /api/vouchers
    public function index(Request $request)
    {
        $vouchers = $this->voucherService->getPaginated($request);

        return response()->json([
            'success' => true,
            'data'    => $vouchers,
        ]);
    }

    // GET /api/vouchers/stats
    public function stats()
    {
        $stats = $this->voucherService->getStats();

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    // POST /api/vouchers/bulk-generate
    public function bulkGenerate(Request $request)
    {
        $request->validate([
            'plan_id'     => 'required|exists:plans,id',
            'quantity'    => 'required|integer|min:1|max:1000',
            'expiry_days' => 'nullable|integer|min:1|max:365',
        ]);

        $plan = Plan::findOrFail($request->plan_id);
        $result = $this->voucherService->bulkGenerate(
            $plan,
            $request->quantity,
            $request->expiry_days,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Vouchers generated successfully',
            'data'    => $result,
        ], 201);
    }

    // GET /api/vouchers/{id}
    public function show(Voucher $voucher)
    {
        $voucher->load('plan', 'redeemedBy', 'createdBy');

        return response()->json([
            'success' => true,
            'data'    => $voucher,
        ]);
    }

    // DELETE /api/vouchers/{id} — only admin can delete unused vouchers
    public function destroy(Request $request, Voucher $voucher)
    {
        if ($voucher->status !== 'unused') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete redeemed or expired vouchers',
            ], 422);
        }

        $voucher->delete();

        return response()->json([
            'success' => true,
            'message' => 'Voucher deleted',
        ]);
    }
}
