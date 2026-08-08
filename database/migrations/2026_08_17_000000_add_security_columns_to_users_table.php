<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add security-related columns to the users table:
     * - allowed_ips: optional per-user IP allowlist (JSON array) enforced by
     *   the IpRestriction middleware.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('allowed_ips')->nullable()->after('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allowed_ips');
        });
    }
};

