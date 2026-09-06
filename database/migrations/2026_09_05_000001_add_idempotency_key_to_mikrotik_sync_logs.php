<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: provisioning idempotency.
 *
 * Each provisioning operation (provision|suspend|activate|deprovision) records
 * an idempotency key alongside its structured audit row. A successfully
 * recorded key makes re-executing the same attempt a no-op, while failed
 * attempts carry the same key forward through retries so backoff re-runs are
 * still attributable to one logical attempt. Manual retries mint a fresh key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_sync_logs', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->after('attempts');
            $table->index(['client_account_id', 'operation', 'idempotency_key'], 'mikrotik_idempotency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_sync_logs', function (Blueprint $table) {
            $table->dropIndex('mikrotik_idempotency_idx');
            $table->dropColumn('idempotency_key');
        });
    }
};