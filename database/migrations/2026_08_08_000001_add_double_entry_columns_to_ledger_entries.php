<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Advanced Billing & Finance.
 *
 * Converts the single-entry ledger into a proper double-entry ledger:
 *
 *  1. `direction`  ('debit' | 'credit')      — which side of the ledger this line lives on.
 *  2. `account_type`                         — the general-ledger account this line posts to;
 *                                               enables trial balance reporting.
 *  3. `counter_entry_id`                     — links the two balanced legs of a posting.
 *  4. `reference` (UUID)                     — groups all legs of a single business event,
 *                                               making reversal-pair detection trivial.
 *
 * Every business event (invoice issue, payment, refund, wallet movement,
 * credit/debit note, …) must post a balanced pair: sum(debits) == sum(credits)
 * for the pair — and therefore globally across the whole ledger.
 *
 * Existing rows are back-filled with sensible directions based on their
 * entry_type so the historical ledger remains coherent.
 */
return new class extends Migration
{
    public function up(): void
{
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('direction', 10)->nullable()->after('entry_type');
            $table->string('account_type', 50)->nullable()->after('direction');
            $table->foreignId('counter_entry_id')->nullable()->after('account_type');
            $table->string('reference', 36)->nullable()->after('counter_entry_id');
        });

        // Back-fill directions / account types for existing single-sided entries.
        $mapping = [
            'invoice_debit'     => ['direction' => 'debit',  'account_type' => 'accounts_receivable'],
            'invoice_reversal'  => ['direction' => 'credit', 'account_type' => 'accounts_receivable'],
            'payment_credit'    => ['direction' => 'credit', 'account_type' => 'accounts_receivable'],
            'payment_reversal'  => ['direction' => 'debit',  'account_type' => 'accounts_receivable'],
            'adjustment'        => ['direction' => 'debit',  'account_type' => 'adjustments'],
        ];

        foreach ($mapping as $type => $values) {
            DB::table('ledger_entries')
                ->where('entry_type', $type)
                ->whereNull('direction')
                ->update($values);
        }

        // Fallback for any stragglers.
        DB::table('ledger_entries')
            ->whereNull('direction')
            ->update(['direction' => 'debit', 'account_type' => 'accounts_receivable']);

        // Extend the entry_type enum with all Phase 6 types.
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_entry_type_check");
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_entry_type_check CHECK (entry_type::text = ANY (ARRAY['invoice_debit'::text, 'payment_credit'::text, 'payment_reversal'::text, 'adjustment'::text, 'invoice_reversal'::text, 'revenue_credit'::text, 'revenue_debit'::text, 'cash_debit'::text, 'cash_credit'::text, 'wallet_deposit'::text, 'wallet_withdrawal'::text, 'wallet_liability_debit'::text, 'wallet_liability_credit'::text, 'refund_issued'::text, 'refund_reversal'::text, 'credit_note'::text, 'credit_note_reversal'::text, 'debit_note'::text, 'debit_note_reversal'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN entry_type ENUM('invoice_debit','payment_credit','payment_reversal','adjustment','invoice_reversal','revenue_credit','revenue_debit','cash_debit','cash_credit','wallet_deposit','wallet_withdrawal','wallet_liability_debit','wallet_liability_credit','refund_issued','refund_reversal','credit_note','credit_note_reversal','debit_note','debit_note_reversal') NOT NULL");
        }
        // SQLite: no native enum — nothing to do.

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['direction', 'entry_type']);
            $table->index('account_type');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_entry_type_check");
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_entry_type_check CHECK (entry_type::text = ANY (ARRAY['invoice_debit'::text, 'payment_credit'::text, 'payment_reversal'::text, 'adjustment'::text, 'invoice_reversal'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN entry_type ENUM('invoice_debit','payment_credit','payment_reversal','adjustment','invoice_reversal') NOT NULL");
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['direction', 'entry_type']);
            $table->dropIndex(['account_type']);
            $table->dropIndex(['reference']);
            $table->dropColumn(['direction', 'account_type', 'counter_entry_id', 'reference']);
        });
    }
};
