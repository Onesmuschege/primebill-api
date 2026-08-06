<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Fixes two enum mismatches that cause DB errors at runtime:
 *
 * 1. invoices.status — PaymentService writes 'partial' when a payment only
 *    partially covers an invoice, but the enum was ['draft','unpaid','paid',
 *    'overdue','cancelled']. 'partial' is now allowed.
 *
 * 2. ledger_entries.entry_type — LedgerService::postInvoiceReversal() writes
 *    'invoice_reversal' when an invoice is deleted, but the enum was
 *    ['invoice_debit','payment_credit','payment_reversal','adjustment'].
 *    'invoice_reversal' is now allowed.
 *
 * Uses raw ALTER to change the enum on both MySQL and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: drop + re-add the constraint with the new value.
            DB::statement("ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check");
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status::text = ANY (ARRAY['draft'::text, 'unpaid'::text, 'paid'::text, 'overdue'::text, 'cancelled'::text, 'partial'::text]))");

            DB::statement("ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_entry_type_check");
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_entry_type_check CHECK (entry_type::text = ANY (ARRAY['invoice_debit'::text, 'payment_credit'::text, 'payment_reversal'::text, 'adjustment'::text, 'invoice_reversal'::text]))");
        } elseif ($driver === 'sqlite') {
            // SQLite: no native enum support — skip (string columns handle this fine).
        } else {
            // MySQL: full enum replace.
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','unpaid','paid','overdue','cancelled','partial') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN entry_type ENUM('invoice_debit','payment_credit','payment_reversal','adjustment','invoice_reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check");
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status::text = ANY (ARRAY['draft'::text, 'unpaid'::text, 'paid'::text, 'overdue'::text, 'cancelled'::text]))");

            DB::statement("ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_entry_type_check");
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_entry_type_check CHECK (entry_type::text = ANY (ARRAY['invoice_debit'::text, 'payment_credit'::text, 'payment_reversal'::text, 'adjustment'::text]))");
        } elseif ($driver === 'sqlite') {
            // SQLite: no native enum support — skip (string columns handle this fine).
        } else {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','unpaid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN entry_type ENUM('invoice_debit','payment_credit','payment_reversal','adjustment') NOT NULL");
        }
    }
};
