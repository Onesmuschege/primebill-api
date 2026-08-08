<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double-entry ledger service.
 *
 * Every business event posts a balanced pair of LedgerEntry rows:
 *   - one debit leg
 *   - one credit leg
 * linked by `counter_entry_id` and grouped by a shared `reference` UUID.
 *
 * The ledger is the single source of truth. Wallet balances, invoice
 * balances, trial balance etc. are all derived projections of these rows.
 * Never create ledger entries outside this service.
 */
class LedgerService
{
    /**
     * Post a balanced debit/credit pair.
     *
     * @param array $debit  [client_id, entry_type, account_type, amount, description, meta, invoice_id, payment_id]
     * @param array $credit [client_id, entry_type, account_type, amount, description, meta, invoice_id, payment_id]
     * @return array{debit: LedgerEntry, credit: LedgerEntry}
     */
    public function postPair(array $debit, array $credit, ?int $userId = null): array
    {
        return DB::transaction(function () use ($debit, $credit, $userId) {
            $reference = (string) Str::uuid();

            $debitEntry = LedgerEntry::create(array_merge($debit, [
                'direction'   => 'debit',
                'currency'    => $debit['currency'] ?? 'KES',
                'reference'   => $reference,
                'recorded_by' => $userId,
            ]));

            $creditEntry = LedgerEntry::create(array_merge($credit, [
                'direction'        => 'credit',
                'currency'         => $credit['currency'] ?? 'KES',
                'reference'        => $reference,
                'counter_entry_id' => $debitEntry->id,
                'recorded_by'      => $userId,
            ]));

            $debitEntry->update(['counter_entry_id' => $creditEntry->id]);

            return ['debit' => $debitEntry, 'credit' => $creditEntry];
        });
    }

    /**
     * Post a single-sided legacy entry (used only for back-compat paths).
     * Prefer postPair() for all new code.
     */
    public function postSingle(array $data, ?int $userId = null): LedgerEntry
    {
        return LedgerEntry::create(array_merge($data, [
            'direction'   => $data['direction'] ?? 'debit',
            'currency'    => $data['currency'] ?? 'KES',
            'reference'   => $data['reference'] ?? (string) Str::uuid(),
            'recorded_by' => $userId,
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoice postings
    // ─────────────────────────────────────────────────────────────────────

    public function postInvoiceDebit(Invoice $invoice, ?int $userId = null): array
    {
        $amount = (float) $invoice->total;

        return $this->postPair(
            [
                'client_id'   => $invoice->client_id,
                'invoice_id'  => $invoice->id,
                'entry_type'  => 'invoice_debit',
                'account_type' => 'accounts_receivable',
                'amount'      => $amount,
                'description' => 'Invoice issued',
                'meta'        => ['invoice_number' => $invoice->invoice_number],
            ],
            [
                'client_id'   => $invoice->client_id,
                'invoice_id'  => $invoice->id,
                'entry_type'  => 'revenue_credit',
                'account_type' => 'revenue',
                'amount'      => $amount,
                'description' => 'Revenue recognized on invoice issue',
                'meta'        => ['invoice_number' => $invoice->invoice_number],
            ],
            $userId
        );
    }

    public function postPaymentCredit(Payment $payment, ?int $userId = null): array
    {
        $amount = (float) $payment->amount;

        return $this->postPair(
            [
                'client_id'    => $payment->client_id,
                'invoice_id'   => $payment->invoice_id,
                'payment_id'   => $payment->id,
                'entry_type'   => 'cash_debit',
                'account_type' => 'cash',
                'amount'       => $amount,
                'description'  => 'Payment received',
                'meta'         => [
                    'method'     => $payment->method,
                    'reference'  => $payment->reference,
                    'mpesa_code' => $payment->mpesa_code,
                ],
            ],
            [
                'client_id'    => $payment->client_id,
                'invoice_id'   => $payment->invoice_id,
                'payment_id'   => $payment->id,
                'entry_type'   => 'payment_credit',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => 'Payment applied to invoice',
                'meta'         => [
                    'method'     => $payment->method,
                    'reference'  => $payment->reference,
                    'mpesa_code' => $payment->mpesa_code,
                ],
            ],
            $userId
        );
    }

    public function postPaymentReversal(Payment $payment, ?int $userId = null, ?string $reason = null): array
    {
        $amount = (float) $payment->amount;

        return $this->postPair(
            [
                'client_id'    => $payment->client_id,
                'invoice_id'   => $payment->invoice_id,
                'payment_id'   => $payment->id,
                'entry_type'   => 'payment_reversal',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => 'Payment reversed',
                'meta'         => [
                    'method'    => $payment->method,
                    'reference' => $payment->reference,
                    'reason'    => $reason ?: 'Payment deleted',
                ],
            ],
            [
                'client_id'    => $payment->client_id,
                'invoice_id'   => $payment->invoice_id,
                'payment_id'   => $payment->id,
                'entry_type'   => 'cash_credit',
                'account_type' => 'cash',
                'amount'       => $amount,
                'description'  => 'Cash reversed on payment deletion',
                'meta'         => [
                    'method'    => $payment->method,
                    'reference' => $payment->reference,
                    'reason'    => $reason ?: 'Payment deleted',
                ],
            ],
            $userId
        );
    }

    public function postInvoiceReversal(Invoice $invoice, ?int $userId = null): array
    {
        $amount = (float) $invoice->total;

        return $this->postPair(
            [
                'client_id'    => $invoice->client_id,
                'invoice_id'   => $invoice->id,
                'entry_type'   => 'invoice_reversal',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => 'Invoice deleted',
                'meta'         => ['invoice_number' => $invoice->invoice_number],
            ],
            [
                'client_id'    => $invoice->client_id,
                'invoice_id'   => $invoice->id,
                'entry_type'   => 'revenue_debit',
                'account_type' => 'revenue',
                'amount'       => $amount,
                'description'  => 'Revenue reversed on invoice deletion',
                'meta'         => ['invoice_number' => $invoice->invoice_number],
            ],
            $userId
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Wallet postings
    // ─────────────────────────────────────────────────────────────────────

    public function postWalletDeposit(int $clientId, float $amount, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'entry_type'   => 'cash_debit',
                'account_type' => 'cash',
                'amount'       => $amount,
                'description'  => $description ?: 'Wallet deposit',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'entry_type'   => 'wallet_liability_credit',
                'account_type' => 'wallet_liability',
                'amount'       => $amount,
                'description'  => $description ?: 'Wallet deposit',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    public function postWalletWithdrawal(int $clientId, float $amount, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'entry_type'   => 'wallet_liability_debit',
                'account_type' => 'wallet_liability',
                'amount'       => $amount,
                'description'  => $description ?: 'Wallet withdrawal',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'entry_type'   => 'cash_credit',
                'account_type' => 'cash',
                'amount'       => $amount,
                'description'  => $description ?: 'Wallet withdrawal',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Refund postings
    // ─────────────────────────────────────────────────────────────────────

    public function postRefundIssued(int $clientId, float $amount, ?int $paymentId = null, ?int $invoiceId = null, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'payment_id'   => $paymentId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'refund_issued',
                'account_type' => 'refunds_payable',
                'amount'       => $amount,
                'description'  => $description ?: 'Refund issued',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'payment_id'   => $paymentId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'cash_credit',
                'account_type' => 'cash',
                'amount'       => $amount,
                'description'  => $description ?: 'Refund issued',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    public function postRefundReversal(int $clientId, float $amount, ?int $paymentId = null, ?int $invoiceId = null, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'payment_id'   => $paymentId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'cash_debit',
                'account_type' => 'cash',
                'amount'       => $amount,
                'description'  => $description ?: 'Refund reversed',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'payment_id'   => $paymentId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'refund_reversal',
                'account_type' => 'refunds_payable',
                'amount'       => $amount,
                'description'  => $description ?: 'Refund reversed',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Credit / Debit note postings
    // ─────────────────────────────────────────────────────────────────────

    public function postCreditNote(int $clientId, float $amount, ?int $invoiceId = null, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'credit_note',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => $description ?: 'Credit note issued',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'revenue_debit',
                'account_type' => 'revenue',
                'amount'       => $amount,
                'description'  => $description ?: 'Credit note issued',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    public function postCreditNoteReversal(int $clientId, float $amount, ?int $invoiceId = null, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'credit_note_reversal',
                'account_type' => 'revenue',
                'amount'       => $amount,
                'description'  => $description ?: 'Credit note reversed',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'credit_note_reversal',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => $description ?: 'Credit note reversed',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    public function postDebitNote(int $clientId, float $amount, ?int $invoiceId = null, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'debit_note',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => $description ?: 'Debit note issued',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'revenue_credit',
                'account_type' => 'revenue',
                'amount'       => $amount,
                'description'  => $description ?: 'Debit note issued',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    public function postDebitNoteReversal(int $clientId, float $amount, ?int $invoiceId = null, ?int $userId = null, ?string $description = null, array $meta = []): array
    {
        return $this->postPair(
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'debit_note_reversal',
                'account_type' => 'revenue',
                'amount'       => $amount,
                'description'  => $description ?: 'Debit note reversed',
                'meta'         => $meta,
            ],
            [
                'client_id'    => $clientId,
                'invoice_id'   => $invoiceId,
                'entry_type'   => 'debit_note_reversal',
                'account_type' => 'accounts_receivable',
                'amount'       => $amount,
                'description'  => $description ?: 'Debit note reversed',
                'meta'         => $meta,
            ],
            $userId
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Balance helpers
    // ─────────────────────────────────────────────────────────────────────

    public function getClientLedgerBalance(int $clientId): float
    {
        $debits = (float) LedgerEntry::where('client_id', $clientId)
            ->where('direction', 'debit')
            ->sum('amount');

        $credits = (float) LedgerEntry::where('client_id', $clientId)
            ->where('direction', 'credit')
            ->sum('amount');

        return round($debits - $credits, 2);
    }

    public function isBalanced(): bool
    {
        $debits = (float) LedgerEntry::where('direction', 'debit')->sum('amount');
        $credits = (float) LedgerEntry::where('direction', 'credit')->sum('amount');

        return abs($debits - $credits) < 0.01;
    }
}
