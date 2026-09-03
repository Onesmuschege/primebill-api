<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Router health / reachability columns (Core ISP Gate — Section 44).
 *
 * Distinguishes CONFIGURED (row exists) from REACHABLE (probe succeeded)
 * from SYNCHRONIZED (last successful provisioning/communication), so an
 * unreachable router can no longer appear operational merely because
 * `status = online` in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('health_state')->default('unknown')
                ->comment('healthy|degraded|unavailable|unknown — result of the last live probe');
            $table->timestamp('last_health_check_at')->nullable()
                ->comment('when the last live probe ran');
            $table->text('last_health_error')->nullable()
                ->comment('error detail from the last failed probe');
            $table->timestamp('last_sync_at')->nullable()
                ->comment('last successful provisioning/communication with this router');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'health_state', 'last_health_check_at',
                'last_health_error', 'last_sync_at',
            ]);
        });
    }
};
