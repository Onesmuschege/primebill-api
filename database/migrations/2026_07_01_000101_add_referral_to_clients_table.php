<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('referral_code', 16)->unique()->nullable()->after('status');
            $table->foreignId('referred_by')->nullable()->constrained('clients')->nullOnDelete();
            $table->integer('referral_count')->default(0);
            $table->integer('referral_bonus')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referred_by', 'referral_count', 'referral_bonus']);
        });
    }
};
