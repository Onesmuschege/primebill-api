<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mfa_enabled')->default(false)->after('email_verified_at');
            $table->string('mfa_secret')->nullable()->after('mfa_enabled');
            $table->text('mfa_backup_codes')->nullable()->after('mfa_secret');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_backup_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_enabled', 'mfa_secret', 'mfa_backup_codes', 'mfa_enabled_at']);
        });
    }
};
