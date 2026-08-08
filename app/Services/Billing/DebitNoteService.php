<?php

namespace App\Services\Billing;

use App\Models\DebitNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DebitNoteService
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    public function issue(array $data, ?int $userId = null): DebitNote
    {
        return DB::transaction(function () use ($data, $userId) {
            $invoice = isset($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;

            if ($invoice && $invoice->client_id !== $data['client_id']) {
                throw new RuntimeException('Invoice does not belong to this client.');
            }

            $debitNote = DebitNote::create([
                'tenant_id'        => $data['tenant_id'] ?? auth()->user()?->tenant_id,
                'client_id'        => $data['client_id'],
                'invoice_id'       => $data['invoice_id'] ?? null,
                'debit_note_number' => $this->generateNumber(),
                'amount'           => $data['amount'],
                'currency'         => $data['currency'] ?? 'KES',
                'status'           => 'issued',
                'reason'           => $data['reason'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'reference'        => (string) Str::uuid(),
                'created_by'       => $userId,
            ]);

            // Post balanced ledger pair
            $this->ledgerService->postDebitNote(
                $debitNote->client_id,
                (float) $debitNote->amount,
                $debitNote->invoice_id,
                $userId,
                'Debit note ' . $debitNote->debit_note_number . ' issued',
                ['debit_note_number' => $debitNote->debit_note_number]
            );

            return $debitNote;
        });
    }

    public function reverse(DebitNote $debitNote, ?int $userId = null, ?string $reason = null): DebitNote
    {
        if ($debitNote->isReversed()) {
            throw new RuntimeException('Debit note is already reversed.');
        }

        return DB::transaction(function () use ($debitNote, $userId, $reason) {
            $this->ledgerService->postDebitNoteReversal(
                $debitNote->client_id,
                (float) $debitNote->amount,
                $debitNote->invoice_id,
                $userId,
                'Debit note ' . $debitNote->debit_note_number . ' reversed',
                ['debit_note_number' => $debitNote->debit_note_number, 'reason' => $reason]
            );

            $debitNote->update([
                'status'      => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $debitNote;
        });
    }

    public function generateNumber(): string
    {
        $prefix = 'DN';
        $year   = date('Y');

        $last = DebitNote::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        $number = $last
            ? (intval(substr($last->debit_note_number, -6)) + 1)
            : 1;

        return $prefix . '-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
}
