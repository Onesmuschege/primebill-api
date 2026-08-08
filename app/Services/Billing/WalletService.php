<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    public function getOrCreateWallet(int $clientId): Wallet
    {
        $client = Client::findOrFail($clientId);

        return Wallet::firstOrCreate(
            ['client_id' => $clientId],
            [
                'tenant_id' => $client->tenant_id,
                'balance'   => 0,
                'currency'  => 'KES',
                'status'    => 'active',
            ]
        );
    }

    public function getBalance(int $clientId): float
    {
        $wallet = $this->getOrCreateWallet($clientId);
        return (float) $wallet->balance;
    }

    public function deposit(int $clientId, float $amount, ?int $userId = null, ?string $description = null, array $meta = []): WalletTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Deposit amount must be positive.');
        }

        return DB::transaction(function () use ($clientId, $amount, $userId, $description, $meta) {
            $wallet = $this->getOrCreateWallet($clientId);

            if ($wallet->status !== 'active') {
                throw new RuntimeException('Wallet is not active.');
            }

            // Post balanced ledger pair (cash debit / wallet liability credit)
            $this->ledgerService->postWalletDeposit(
                $clientId,
                $amount,
                $userId,
                $description,
                $meta
            );

            $newBalance = round((float) $wallet->balance + $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            return WalletTransaction::create([
                'tenant_id'     => $wallet->tenant_id,
                'wallet_id'     => $wallet->id,
                'client_id'     => $clientId,
                'type'          => 'deposit',
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'reference'     => $meta['reference'] ?? null,
                'description'   => $description,
                'meta'          => $meta,
                'recorded_by'   => $userId,
            ]);
        });
    }

    public function withdraw(int $clientId, float $amount, ?int $userId = null, ?string $description = null, array $meta = []): WalletTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Withdrawal amount must be positive.');
        }

        return DB::transaction(function () use ($clientId, $amount, $userId, $description, $meta) {
            $wallet = $this->getOrCreateWallet($clientId);

            if ($wallet->status !== 'active') {
                throw new RuntimeException('Wallet is not active.');
            }

            if ((float) $wallet->balance < $amount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            // Post balanced ledger pair (wallet liability debit / cash credit)
            $this->ledgerService->postWalletWithdrawal(
                $clientId,
                $amount,
                $userId,
                $description,
                $meta
            );

            $newBalance = round((float) $wallet->balance - $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            return WalletTransaction::create([
                'tenant_id'     => $wallet->tenant_id,
                'wallet_id'     => $wallet->id,
                'client_id'     => $clientId,
                'type'          => 'withdrawal',
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'reference'     => $meta['reference'] ?? null,
                'description'   => $description,
                'meta'          => $meta,
                'recorded_by'   => $userId,
            ]);
        });
    }

    public function payInvoice(int $clientId, int $invoiceId, float $amount, ?int $userId = null): WalletTransaction
    {
        return DB::transaction(function () use ($clientId, $invoiceId, $amount, $userId) {
            $wallet = $this->getOrCreateWallet($clientId);

            if ((float) $wallet->balance < $amount) {
                throw new RuntimeException('Insufficient wallet balance to pay invoice.');
            }

            $this->ledgerService->postWalletWithdrawal(
                $clientId,
                $amount,
                $userId,
                'Wallet payment applied to invoice',
                ['invoice_id' => $invoiceId]
            );

            $newBalance = round((float) $wallet->balance - $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            return WalletTransaction::create([
                'tenant_id'     => $wallet->tenant_id,
                'wallet_id'     => $wallet->id,
                'client_id'     => $clientId,
                'type'          => 'payment',
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'reference'     => null,
                'description'   => 'Wallet payment applied to invoice #' . $invoiceId,
                'meta'          => ['invoice_id' => $invoiceId],
                'recorded_by'   => $userId,
            ]);
        });
    }

    public function getTransactions(int $clientId, ?int $limit = 50)
    {
        $wallet = $this->getOrCreateWallet($clientId);

        return WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
