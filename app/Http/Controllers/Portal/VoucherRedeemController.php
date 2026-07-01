<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Billing\VoucherService;
use Illuminate\Http\Request;

class VoucherRedeemController extends Controller
{
    protected VoucherService $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    /**
     * POST /api/portal/vouchers/redeem
     * Client redeems a voucher to create a prepaid account
     */
    public function redeem(Request $request)
    {
        $request->validate([
            'code'     => 'required|string|size:19', // XXXX-XXXX-XXXX-XXXX
            'username' => 'required|string|min:3|max:32|regex:/^[a-zA-Z0-9._-]+$/',
            'password' => 'required|string|min:6|confirmed',
            'email'    => 'nullable|email',
        ]);

        $result = $this->voucherService->redeem(
            $request->code,
            $request->username,
            $request->password,
            $request->email,
            $request->user()->id
        );

        if (!$result['success']) {
            return response()->json($result, 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data'    => [
                'username'    => $result['account']['username'],
                'expiry_date' => $result['account']['expiry_date'],
            ],
        ], 201);
    }

    /**
     * GET /api/portal/vouchers/check/{code}
     * Verify if a voucher code exists and is redeemable
     */
    public function check($code)
    {
        $voucher = \App\Models\Voucher::where('code', $code)->first();

        if (!$voucher) {
            return response()->json([
                'valid'   => false,
                'message' => 'Invalid voucher code',
            ]);
        }

        if ($voucher->status !== 'unused') {
            return response()->json([
                'valid'   => false,
                'message' => 'This voucher has already been used',
            ]);
        }

        if ($voucher->isExpired()) {
            return response()->json([
                'valid'   => false,
                'message' => 'This voucher has expired',
            ]);
        }

        return response()->json([
            'valid' => true,
            'plan'  => [
                'name'        => $voucher->plan->name,
                'speed_up'    => $voucher->plan->speed_up,
                'speed_down'  => $voucher->plan->speed_down,
                'validity'    => $voucher->plan->validity_days,
                'fup_limit'   => $voucher->plan->fup_limit,
            ],
        ]);
    }
}
