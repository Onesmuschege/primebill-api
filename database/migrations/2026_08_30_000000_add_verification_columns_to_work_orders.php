<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work-order verification — completes the field-ops lifecycle.
     *
     * A work order is "completed" by the technician (materials + evidence +
     * signature captured) and then independently "verified" by an operations
     * lead / QA reviewer who closes the loop on the outcome (Release 4).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('work_orders', 'verified_at')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable()->after('completed_at');
                $table->foreignId('verified_by')->nullable()->after('verified_at')
                    ->constrained('users')->nullOnDelete();
                $table->text('verification_notes')->nullable()->after('verified_by');

                $table->index(['tenant_id', 'verified_at'], 'work_orders_tenant_verified_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_orders', 'verified_at')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropIndex('work_orders_tenant_verified_index');
                $table->dropConstrainedForeignId('verified_by');
                $table->dropColumn(['verified_at', 'verification_notes']);
            });
        }
    }
};
