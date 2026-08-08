<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radius_sessions', function (Blueprint $table) {
            $table->string('session_id')->nullable()->after('status');
            $table->foreignId('nas_id')->nullable()->after('session_id')
                ->constrained('routers')->nullOnDelete();
            $table->string('mac_address')->nullable()->after('nas_id');
            $table->string('nas_port_id')->nullable()->after('mac_address');
            $table->string('calling_station_id')->nullable()->after('nas_port_id');
            $table->string('called_station_id')->nullable()->after('calling_station_id');
            $table->string('framed_protocol')->nullable()->after('called_station_id');
            $table->string('service_type')->nullable()->after('framed_protocol');
            $table->string('terminate_cause')->nullable()->after('service_type');
            $table->timestamp('last_seen_at')->nullable()->after('terminate_cause');
            $table->integer('acct_session_time')->nullable()->after('last_seen_at');
            $table->integer('acct_interval')->nullable()->after('acct_session_time');
            $table->string('access_method')->nullable()->after('acct_interval');

            $table->index(['nas_id', 'status']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('radius_sessions', function (Blueprint $table) {
            $table->dropIndex(['nas_id', 'status']);
            $table->dropIndex(['last_seen_at']);
            $table->dropForeign(['nas_id']);
            $table->dropColumn([
                'session_id', 'nas_id', 'mac_address', 'nas_port_id',
                'calling_station_id', 'called_station_id', 'framed_protocol',
                'service_type', 'terminate_cause', 'last_seen_at',
                'acct_session_time', 'acct_interval', 'access_method',
            ]);
        });
    }
};
