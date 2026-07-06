<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // referral_code and referred_by are already added in
        // 2026_07_01_000100_create_loyalty_points_table.php — only add
        // the columns that migration doesn't cover.
        Schema::table('clients', function (Blueprint $table) {
            $table->integer('referral_count')->default(0);
            $table->integer('referral_bonus')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['referral_count', 'referral_bonus']);
        });
    }
};