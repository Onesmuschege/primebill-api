<?php

namespace App\Services\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreditNoteService
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    public function issue(array $data, ?int $userId = null): CreditNote
    {
        return DB::transaction(function () use ($data, $userId) {
            $invoice = isset($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;

            if ($invoice && $invoice->client_id !== $data['client_id']) {
                throw new RuntimeException('Invoice does not belong to this client.');
            }

            $creditNote = CreditNote::create([
                'tenant_id'         => $data['tenant_id'] ?? auth()->user()?->tenant_id,
                'client_id'         => $data['client_id'],
                'invoice_id'        => $data['invoice_id'] ?? null,
                'credit_note_number' => $this->generateNumber(),
                'amount'            => $data['amount'],
                'currency'          => $data['currency'] ?? 'KES',
                'status'            => 'issued',
                'reason'            => $data['reason'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'reference'         => (string) Str::uuid(),
                'created_by'        => $userId,
            ]);

            // Post balanced ledger pair
            $this->ledgerService->postCreditNote(
                $creditNote->client_id,
                (float) $creditNote->amount,
                $creditNote->invoice_id,
                $userId,
                'Credit note ' . $creditNote->credit_note_number . ' issued',
                ['credit_note_number' => $creditNote->credit_note_number]
            );

            return $creditNote;
        });
    }

    public function reverse(CreditNote $creditNote, ?int $userId = null, ?string $reason = null): CreditNote
    {
        if ($creditNote->isReversed()) {
            throw new RuntimeException('Credit note is already reversed.');
        }

        return DB::transaction(function () use ($creditNote, $userId, $reason) {
            $this->ledgerService->postCreditNoteReversal(
                $creditNote->client_id,
                (float) $creditNote->amount,
                $creditNote->invoice_id,
                $userId,
                'Credit note ' . $creditNote->credit_note_number . ' reversed',
                ['credit_note_number' => $creditNote->credit_note_number, 'reason' => $reason]
            );

            $creditNote->update([
                'status'      => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $creditNote;
        });
    }

    public function generateNumber(): string
    {
        $prefix = 'CN';
        $year   = date('Y');

        $last = CreditNote::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        $number = $last
            ? (intval(substr($last->credit_note_number, -6)) + 1)
            : 1;

        return $prefix . '-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
}
