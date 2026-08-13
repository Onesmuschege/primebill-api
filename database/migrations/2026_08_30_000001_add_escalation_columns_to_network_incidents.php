<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incident escalation — NOC staff escalate an incident when the current
     * severity / team is not enough. Escalation raises the level (0 → 1 → 2),
     * stamps who/when/why, and keeps the incident in its current lifecycle
     * state (escalation is orthogonal to the detected → … → closed flow).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('network_incidents', 'escalation_level')) {
            Schema::table('network_incidents', function (Blueprint $table) {
                $table->unsignedTinyInteger('escalation_level')->default(0)->after('severity');
                $table->timestamp('escalated_at')->nullable()->after('escalation_level');
                $table->foreignId('escalated_by')->nullable()->after('escalated_at')
                    ->constrained('users')->nullOnDelete();
                $table->text('escalation_reason')->nullable()->after('escalated_by');

                $table->index(['tenant_id', 'escalation_level'], 'network_incidents_tenant_escalation_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('network_incidents', 'escalation_level')) {
            Schema::table('network_incidents', function (Blueprint $table) {
                $table->dropIndex('network_incidents_tenant_escalation_index');
                $table->dropConstrainedForeignId('escalated_by');
                $table->dropColumn(['escalation_level', 'escalated_at', 'escalation_reason']);
            });
        }
    }
};
