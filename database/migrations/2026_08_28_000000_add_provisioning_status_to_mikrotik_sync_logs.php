<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured provisioning/AAA result tracking.
 *
 * Extends the existing (unstructured) mikrotik_sync_logs into a machine-
 * readable audit of every provisioning operation so the R3 requirements of
 * status / failure reason / retry / manual retry are supported. All columns
 * are nullable ADD COLUMN operations — non-destructive to existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('mikrotik_sync_logs', 'client_account_id')) {
            Schema::table('mikrotik_sync_logs', function (Blueprint $table) {
                $table->foreignId('client_account_id')->nullable()
                    ->constrained('client_accounts')->nullOnDelete();
                $table->string('operation', 30)->nullable();      // provision|suspend|activate|deprovision
                $table->string('status', 20)->nullable()          // success|partial|failed
                    ->index();
                $table->boolean('router_ok')->nullable();
                $table->boolean('radius_ok')->nullable();
                $table->text('failure_reason')->nullable();
                $table->unsignedInteger('attempts')->default(1);
                $table->index(['status', 'client_account_id'], 'mikrotik_sync_status_account_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('mikrotik_sync_logs', function (Blueprint $table) {
            $table->dropForeign(['client_account_id']);
            $table->dropColumn([
                'client_account_id', 'operation', 'status',
                'router_ok', 'radius_ok', 'failure_reason', 'attempts',
            ]);
        });
    }
};