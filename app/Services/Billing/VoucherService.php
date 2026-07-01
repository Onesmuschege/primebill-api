<?php

namespace App\Services\Billing;

use App\Models\Voucher;
use App\Models\Plan;
use App\Models\Client;
use App\Models\ClientAccount;
use Illuminate\Support\Str;
use Illuminate\Pagination\Paginator;

class VoucherService
{
    /**
     * Generate unique voucher codes.
     * Format: 4-character segments separated by hyphens (e.g., ABCD-EFGH-IJKL-MNOP)
     */
    protected function generateCode(): string
    {
        do {
            $code = implode('-', array_map(
                fn() => strtoupper(Str::random(4)),
                range(1, 4)
            ));
        } while (Voucher::where('code', $code)->exists());

        return $code;
    }

    /**
     * Bulk generate vouchers for a plan
     */
    public function bulkGenerate(Plan $plan, int $quantity, ?int $expiryDays, int $createdBy): array
    {
        $vouchers = [];
        $expiresAt = $expiryDays ? now()->addDays($expiryDays) : null;

        for ($i = 0; $i < $quantity; $i++) {
            $vouchers[] = [
                'code'        => $this->generateCode(),
                'plan_id'     => $plan->id,
                'status'      => 'unused',
                'expires_at'  => $expiresAt,
                'created_by'  => $createdBy,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }

        Voucher::insert($vouchers);

        return [
            'plan_id'    => $plan->id,
            'quantity'   => $quantity,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ];
    }

    /**
     * Redeem a voucher and create a prepaid account for the client
     */
    public function redeem(string $code, string $username, string $password, ?string $email, int $clientId): array
    {
        $voucher = Voucher::where('code', $code)->first();

        if (!$voucher) {
            return ['success' => false, 'message' => 'Invalid voucher code'];
        }

        if ($voucher->status !== 'unused') {
            return ['success' => false, 'message' => 'Voucher has already been used'];
        }

        if ($voucher->isExpired()) {
            return ['success' => false, 'message' => 'Voucher has expired'];
        }

        // Check for duplicate username
        if (ClientAccount::where('username', $username)->exists()) {
            return ['success' => false, 'message' => 'Username already taken'];
        }

        try {
            $voucher->update([
                'status'       => 'redeemed',
                'redeemed_by'  => $clientId,
                'redeemed_at'  => now(),
            ]);

            // Create prepaid account
            $account = ClientAccount::create([
                'client_id'   => $clientId,
                'plan_id'     => $voucher->plan_id,
                'username'    => $username,
                'password'    => bcrypt($password),
                'type'        => 'prepaid',
                'status'      => 'active',
                'expiry_date' => now()->addDays($voucher->plan->validity_days),
                'activated_at'=> now(),
            ]);

            return [
                'success'    => true,
                'message'    => 'Voucher redeemed successfully',
                'account'    => $account,
                'expiry_date'=> $account->expiry_date,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to redeem voucher: ' . $e->getMessage()];
        }
    }

    /**
     * Get paginated list of vouchers
     */
    public function getPaginated($request)
    {
        $query = Voucher::with('plan', 'redeemedBy', 'createdBy');

        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->plan_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->search . '%');
        }

        return $query->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));
    }

    /**
     * Get voucher statistics
     */
    public function getStats(): array
    {
        return [
            'total'     => Voucher::count(),
            'unused'    => Voucher::where('status', 'unused')->count(),
            'redeemed'  => Voucher::where('status', 'redeemed')->count(),
            'expired'   => Voucher::where('status', 'expired')->count(),
        ];
    }
}
