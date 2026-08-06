<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            // tenant_id is already added by 2026_08_04_000001_extend_tenant_id_to_remaining_models
            // user_id+created_at index already exists from 2026_04_25_134000_add_soft_deletes_and_core_indexes
            $table->string('request_id')->nullable()->after('user_agent');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            // Only drop request_id — tenant_id is managed by the earlier migration
            $table->dropColumn('request_id');
            $table->dropIndex(['system_logs_tenant_id_created_at_index']);
            $table->dropIndex(['system_logs_action_created_at_index']);
        });
    }
};
