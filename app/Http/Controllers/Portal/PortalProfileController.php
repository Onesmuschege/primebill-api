<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Billing\BalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PortalProfileController extends Controller
{
    // GET /api/portal/profile
    public function index(Request $request)
    {
        $client = $request->user();
        $client->load('accounts.plan');

        return response()->json([
            'success' => true,
            'data'    => $client,
        ]);
    }

    // PUT /api/portal/profile
    public function update(Request $request)
    {
        $request->validate([
            'email' => 'sometimes|email|unique:clients,email,' . $request->user()->id,
            'phone' => 'sometimes|string|unique:clients,phone,' . $request->user()->id,
        ]);

        $request->user()->update($request->only('email', 'phone'));

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data'    => $request->user(),
        ]);
    }

    // GET /api/portal/balance
    public function balance(Request $request, BalanceService $balanceService)
    {
        return response()->json([
            'success' => true,
            'data'    => $balanceService->getClientBalance($request->user()->id),
        ]);
    }

    // POST /api/portal/profile/change-password
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $account = $request->user()
                           ->accounts()
                           ->first();

        if (!$account || !Hash::check($request->current_password, $account->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $account->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
        ]);
    }
}