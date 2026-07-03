<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\Network\ProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    // GET /api/vouchers
    public function index(Request $request)
    {
        $vouchers = Voucher::with('plan:id,name,price', 'redeemedBy:id,first_name,last_name')
            ->when($request->status,  fn($q) => $q->where('status',   $request->status))
            ->when($request->batch,   fn($q) => $q->where('batch',    $request->batch))
            ->when($request->plan_id, fn($q) => $q->where('plan_id',  $request->plan_id))
            ->latest()
            ->paginate(50);

        return response()->json(['success' => true, 'data' => $vouchers]);
    }

    // GET /api/vouchers/stats
    // Aggregates totals across all vouchers — called by getVoucherStats() in the frontend.
    public function stats()
    {
        $row = Voucher::select(
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN status = 'unused'   THEN 1 ELSE 0 END) as unused"),
            DB::raw("SUM(CASE WHEN status = 'redeemed' THEN 1 ELSE 0 END) as redeemed"),
            DB::raw("SUM(CASE WHEN status = 'expired'  THEN 1 ELSE 0 END) as expired")
        )->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'    => (int) ($row->total    ?? 0),
                'unused'   => (int) ($row->unused   ?? 0),
                'redeemed' => (int) ($row->redeemed ?? 0),
                'expired'  => (int) ($row->expired  ?? 0),
            ],
        ]);
    }

    // GET /api/vouchers/batches
    public function batches()
    {
        $batches = Voucher::select(
                'batch',
                'batch_label',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'unused'   THEN 1 ELSE 0 END) as unused"),
                DB::raw("SUM(CASE WHEN status = 'redeemed' THEN 1 ELSE 0 END) as redeemed"),
                DB::raw("SUM(CASE WHEN status = 'expired'  THEN 1 ELSE 0 END) as expired")
            )
            ->groupBy('batch', 'batch_label')
            ->orderByDesc('batch')
            ->get();

        return response()->json(['success' => true, 'data' => $batches]);
    }

    // POST /api/vouchers/generate
    public function generate(Request $request)
    {
        $request->validate([
            'plan_id'     => 'required|exists:plans,id',
            'quantity'    => 'required|integer|min:1|max:500',
            'batch_label' => 'nullable|string|max:100',
            'valid_days'  => 'nullable|integer|min:1',
        ]);

        $batch     = (Voucher::max('batch') ?? 0) + 1;
        $expiresAt = $request->valid_days ? now()->addDays($request->valid_days) : null;

        $vouchers = [];
        DB::transaction(function () use ($request, $batch, $expiresAt, &$vouchers) {
            for ($i = 0; $i < $request->quantity; $i++) {
                $vouchers[] = Voucher::create([
                    'code'        => Voucher::generateCode(),
                    'plan_id'     => $request->plan_id,
                    'created_by'  => $request->user()->id,
                    'batch'       => $batch,
                    'batch_label' => $request->batch_label,
                    'expires_at'  => $expiresAt,
                    'status'      => 'unused',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => count($vouchers) . ' vouchers generated (Batch #' . $batch . ')',
            'data'    => ['batch' => $batch, 'vouchers' => $vouchers],
        ], 201);
    }

    // DELETE /api/vouchers/{voucher}
    // Only unused vouchers can be deleted — redeemed ones are financial records.
    public function destroy(Voucher $voucher)
    {
        if ($voucher->status !== 'unused') {
            return response()->json([
                'success' => false,
                'message' => 'Only unused vouchers can be deleted.',
            ], 422);
        }

        $voucher->delete();

        return response()->json(['success' => true, 'message' => 'Voucher deleted']);
    }

    // POST /api/vouchers/redeem — called from captive portal (no auth)
    public function redeem(Request $request, ProvisioningService $provisioning)
    {
        $request->validate([
            'code'     => 'required|string',
            'username' => 'required|string',
            'phone'    => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $provisioning) {
            $voucher = Voucher::where('code', strtoupper(trim($request->code)))
                ->lockForUpdate()
                ->firstOrFail();

            if ($voucher->status !== 'unused') {
                return response()->json([
                    'success' => false,
                    'message' => $voucher->status === 'redeemed'
                        ? 'This voucher has already been used.'
                        : 'This voucher has expired.',
                ], 422);
            }

            if ($voucher->isExpired()) {
                $voucher->update(['status' => 'expired']);
                return response()->json(['success' => false, 'message' => 'This voucher has expired.'], 422);
            }

            $account = ClientAccount::where('username', $request->username)->first();

            if (!$account) {
                $client = \App\Models\Client::firstOrCreate(
                    ['phone' => $request->phone ?? $request->username],
                    ['first_name' => 'Guest', 'last_name' => $request->username, 'email' => null, 'status' => 'active']
                );

                $plainPassword = \Illuminate\Support\Str::random(12);
                $account = ClientAccount::create([
                    'client_id'    => $client->id,
                    'plan_id'      => $voucher->plan_id,
                    'username'     => $request->username,
                    'password'     => $plainPassword,
                    'type'         => 'prepaid',
                    'status'       => 'active',
                    'expiry_date'  => now()->addDays($voucher->plan->validity_days ?? 1),
                    'activated_at' => now(),
                ]);

                $provisioning->provisionAccount($account, $plainPassword);
            } else {
                $account->update([
                    'plan_id'     => $voucher->plan_id,
                    'status'      => 'active',
                    'expiry_date' => now()->addDays($voucher->plan->validity_days ?? 1),
                ]);
                $provisioning->activateAccount($account);
            }

            $voucher->update([
                'status'      => 'redeemed',
                'redeemed_by' => $account->client_id,
                'redeemed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Voucher redeemed! You are now connected.',
                'data'    => ['plan' => $voucher->plan->name, 'expires_at' => $account->expiry_date],
            ]);
        });
    }
}