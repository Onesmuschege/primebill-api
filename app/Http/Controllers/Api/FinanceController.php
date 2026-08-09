<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Refund;
use App\Models\Wallet;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\DebitNoteService;
use App\Services\Billing\FinancialStatementService;
use App\Services\Billing\PaymentPlanService;
use App\Services\Billing\RefundService;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FinanceController
 *
 * Consolidated API surface for the advanced billing modules that were built
 * as services but were never wired into routes (Wallet, Credit/and Debit
 * notes, Refunds, Payment Plans, Financial Statements, Usage Billing).
 * Reuses the existing Billing services directly — no duplicated logic.
 */
class FinanceController extends Controller
{
    public function __construct(
        protected WalletService $walletService,
        protected CreditNoteService $creditNoteService,
        protected DebitNoteService $debitNoteService,
        protected RefundService $refundService,
        protected PaymentPlanService $paymentPlanService,
        protected FinancialStatementService $financialStatementService,
        protected UsageBillingService $usageBillingService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Wallets
    // ─────────────────────────────────────────────────────────────────────

    public function walletBalance(Request $request): JsonResponse
    {
        $request->validate(['client_id' => 'required|exists:clients,id']);

        return response()->json([
            'success' => true,
            'data'    => ['balance' => $this->walletService->getBalance((int) $request->client_id)],
        ]);
    }

    public function walletDeposit(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'amount'     => 'required|numeric|min:0.01',
            'reference'  => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $transaction = $this->walletService->deposit(
            (int) $request->client_id,
            (float) $request->amount,
            $request->user()->id,
            $request->input('description'),
            ['reference' => $request->input('reference')]
        );

        return response()->json([
            'success' => true,
            'message' => 'Wallet deposit successful',
            'data'    => $transaction->load('wallet', 'client'),
        ], 201);
    }

    public function walletWithdraw(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'amount'     => 'required|numeric|min:0.01',
            'description' => 'nullable|string',
        ]);

        $transaction = $this->walletService->withdraw(
            (int) $request->client_id,
            (float) $request->amount,
            $request->user()->id,
            $request->input('description')
        );

        return response()->json([
            'success' => true,
            'message' => 'Wallet withdrawal successful',
            'data'    => $transaction->load('wallet', 'client'),
        ]);
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $request->validate(['client_id' => 'required|exists:clients,id']);

        $transactions = $this->walletService->getTransactions(
            (int) $request->client_id,
            $request->integer('limit', 50)
        );

        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Credit Notes
    // ─────────────────────────────────────────────────────────────────────

    public function creditNotesIndex(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => CreditNote::with('client', 'invoice')->orderBy('created_at', 'desc')->paginate(15),
        ]);
    }

    public function creditNoteStore(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount'     => 'required|numeric|min:0.01',
            'reason'     => 'nullable|string',
            'notes'      => 'nullable|string',
        ]);

        $creditNote = $this->creditNoteService->issue($request->only([
            'client_id', 'invoice_id', 'amount', 'reason', 'notes',
        ]), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Credit note issued',
            'data'    => $creditNote->load('client', 'invoice'),
        ], 201);
    }

    public function creditNoteReverse(Request $request, CreditNote $creditNote): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);

        $creditNote = $this->creditNoteService->reverse($creditNote, $request->user()->id, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Credit note reversed',
            'data'    => $creditNote,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Debit Notes
    // ─────────────────────────────────────────────────────────────────────

    public function debitNotesIndex(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => DebitNote::with('client', 'invoice')->orderBy('created_at', 'desc')->paginate(15),
        ]);
    }

    public function debitNoteStore(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount'     => 'required|numeric|min:0.01',
            'reason'     => 'nullable|string',
            'notes'      => 'nullable|string',
        ]);

        $debitNote = $this->debitNoteService->issue($request->only([
            'client_id', 'invoice_id', 'amount', 'reason', 'notes',
        ]), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Debit note issued',
            'data'    => $debitNote->load('client', 'invoice'),
        ], 201);
    }

    public function debitNoteReverse(Request $request, DebitNote $debitNote): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);

        $debitNote = $this->debitNoteService->reverse($debitNote, $request->user()->id, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Debit note reversed',
            'data'    => $debitNote,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Refunds
    // ─────────────────────────────────────────────────────────────────────

    public function refundsIndex(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Refund::with('client', 'payment', 'invoice')->orderBy('created_at', 'desc')->paginate(15),
        ]);
    }

    public function refundStore(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'payment_id' => 'required|exists:payments,id',
            'amount'     => 'required|numeric|min:0.01',
            'method'     => 'nullable|string',
            'reason'     => 'nullable|string',
        ]);

        $refund = $this->refundService->issue(array_merge($request->only([
            'client_id', 'payment_id', 'amount', 'method', 'reason',
        ]), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Refund issued',
            'data'    => $refund,
        ], 201);
    }

    public function refundReverse(Request $request, Refund $refund): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);

        $refund = $this->refundService->reverse($refund, $request->user()->id, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Refund reversed',
            'data'    => $refund,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Payment Plans
    // ─────────────────────────────────────────────────────────────────────

    public function paymentPlansIndex(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => PaymentPlan::with('client', 'invoice', 'installments')
                ->orderBy('created_at', 'desc')->paginate(15),
        ]);
    }

    public function paymentPlanStore(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'         => 'required|exists:clients,id',
            'invoice_id'        => 'nullable|exists:invoices,id',
            'total_amount'      => 'nullable|numeric|min:0.01',
            'installment_count' => 'required|integer|min:1',
            'frequency'         => 'nullable|in:weekly,monthly,quarterly',
            'starts_at'         => 'nullable|date',
            'notes'             => 'nullable|string',
        ]);

        $plan = $this->paymentPlanService->create($request->all(), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Payment plan created',
            'data'    => $plan->load('client', 'invoice', 'installments'),
        ], 201);
    }

    public function paymentPlanRecordPayment(Request $request, PaymentPlanInstallment $installment): JsonResponse
    {
        $request->validate(['amount' => 'required|numeric|min:0.01']);

        $installment = $this->paymentPlanService->recordInstallmentPayment(
            $installment,
            (float) $request->amount,
            $request->input('payment_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Installment payment recorded',
            'data'    => $installment->load('paymentPlan.client', 'paymentPlan.invoice'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Financial Statements
    // ─────────────────────────────────────────────────────────────────────

    public function trialBalance(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->financialStatementService->trialBalance(
                $request->input('from'),
                $request->input('to')
            ),
        ]);
    }

    public function revenueRecognition(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->financialStatementService->revenueRecognitionReport(
                $request->input('from'),
                $request->input('to')
            ),
        ]);
    }

    public function verifyLedger(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => ['balanced' => $this->financialStatementService->verifyLedgerBalance()],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Usage Billing
    // ─────────────────────────────────────────────────────────────────────

    public function usageCompute(Request $request): JsonResponse
    {
        $request->validate([
            'client_account_id' => 'required|exists:client_accounts,id',
            'period'            => 'required|date_format:Y-m',
            'rate_per_gb'       => 'nullable|numeric|min:0',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->usageBillingService->computeOverage(
                (int) $request->client_account_id,
                $request->period,
                (float) $request->input('rate_per_gb', 0)
            ),
        ]);
    }

    public function usageRecord(Request $request): JsonResponse
    {
        $request->validate([
            'client_account_id' => 'required|exists:client_accounts,id',
            'period'            => 'required|date_format:Y-m',
            'rate_per_gb'       => 'nullable|numeric|min:0',
        ]);

        $record = $this->usageBillingService->recordUsage(
            (int) $request->client_account_id,
            $request->period,
            (float) $request->input('rate_per_gb', 0)
        );

        return response()->json([
            'success' => true,
            'message' => 'Usage billing recorded',
            'data'    => $record,
        ], 201);
    }
}

