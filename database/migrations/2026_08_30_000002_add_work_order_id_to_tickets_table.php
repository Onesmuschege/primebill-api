<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service-desk ↔ field-ops relationship. A ticket can be raised against
     * (or promote) a work order, giving the support desk a live view of the
     * field dispatch behind a customer complaint (Release 4).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'work_order_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('work_order_id')->nullable()->after('client_id')
                    ->constrained('work_orders')->nullOnDelete();

                $table->index(['tenant_id', 'work_order_id'], 'tickets_tenant_work_order_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'work_order_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex('tickets_tenant_work_order_index');
                $table->dropConstrainedForeignId('work_order_id');
            });
        }
    }
};
