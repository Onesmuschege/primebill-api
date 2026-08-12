<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add SLA enforcement columns to the Service Desk tickets table.
     * Idempotent guards mirror the existing provisioning_status migration.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'sla_policy_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('sla_policy_id')->nullable()->after('assigned_to')
                    ->constrained('sla_policies')->nullOnDelete();
                $table->timestamp('first_responded_at')->nullable();
                $table->timestamp('sla_response_due_at')->nullable();
                $table->timestamp('sla_resolution_due_at')->nullable();
                $table->boolean('sla_breached')->default(false);
                $table->timestamp('last_sla_evaluated_at')->nullable();

                $table->index(['tenant_id', 'sla_breached'], 'tickets_tenant_sla_breached_index');
                $table->index(['tenant_id', 'sla_resolution_due_at'], 'tickets_tenant_sla_resolution_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'sla_policy_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex('tickets_tenant_sla_breached_index');
                $table->dropIndex('tickets_tenant_sla_resolution_index');
                $table->dropConstrainedForeignId('sla_policy_id');
                $table->dropColumn([
                    'first_responded_at',
                    'sla_response_due_at',
                    'sla_resolution_due_at',
                    'sla_breached',
                    'last_sla_evaluated_at',
                ]);
            });
        }
    }
};