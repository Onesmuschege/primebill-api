<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds database-level idempotency guarantees to the payments table.
 *
 * WHY THIS IS NECESSARY:
 * Application-level idempotency checks (if already processed, skip) have a
 * TOCTOU (time-of-check / time-of-use) race window. Under concurrent Safaricom
 * callback retries two processes can both pass the guard before either commits.
 * A unique index is the only guarantee that survives a race — the DB engine
 * serialises the insert and rejects the duplicate at the storage level.
 *
 * COLUMNS:
 * - idempotency_key: The canonical dedup key. For M-Pesa STK: MpesaReceiptNumber.
 *   For C2B: TransID. For cash/bank: caller-supplied UUID. Always present for
 *   mpesa payments; nullable for cash/bank where the caller may not supply one.
 *
 * INDEXES:
 * - UNIQUE(idempotency_key)  — prevents duplicate payment rows at DB level.
 * - UNIQUE(mpesa_code)       — belt-and-suspenders for direct receipt dedup.
 *   Partial (nullable-safe): MySQL/MariaDB treat multiple NULLs as distinct in
 *   unique indexes, so cash/bank payments with no mpesa_code are not affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Add the idempotency_key column after mpesa_code.
            // Nullable so cash/bank payments that don't supply one still insert.
            $table->string('idempotency_key')->nullable()->unique()->after('mpesa_code');

            // Belt-and-suspenders: also unique-index mpesa_code directly.
            // MySQL treats NULL as distinct in unique indexes, so multiple cash
            // payments with NULL mpesa_code are not blocked.
            $table->unique('mpesa_code');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropUnique(['mpesa_code']);
            $table->dropColumn('idempotency_key');
        });
    }
};