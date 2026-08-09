<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('mfa_recovery_attempts')->default(0)->after('mfa_enabled_at');
            $table->timestamp('mfa_recovery_locked_until')->nullable()->after('mfa_recovery_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_recovery_attempts', 'mfa_recovery_locked_until']);
        });
    }
};
