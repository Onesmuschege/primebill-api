<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — Network Operations Center.
     *
     * Extends routers with NOC device attributes and creates the metrics,
     * alerts, and topology (links) tables that power the NOC dashboard.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('device_type')->default('router')->after('type');
            $table->string('model')->nullable()->after('device_type');
            $table->string('vendor')->nullable()->after('model');
            // SNMP credential — encrypted at rest (TEXT column).
            $table->text('snmp_community')->nullable()->after('vendor');
            $table->unsignedInteger('snmp_port')->default(161)->after('snmp_community');
            $table->string('snmp_version')->default('2c')->after('snmp_port');
            $table->decimal('location_lat', 10, 7)->nullable()->after('snmp_version');
            $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
        });

        Schema::create('device_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('routers')->cascadeOnDelete();
            $table->string('metric_type'); // cpu, ram, temp, traffic, interface_util, errors, drops, uptime, latency
            $table->decimal('value', 15, 2);
            $table->string('interface')->nullable();
            $table->string('unit')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'metric_type', 'recorded_at']);
            $table->index(['device_id', 'recorded_at']);
        });

        Schema::create('network_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('routers')->cascadeOnDelete();
            $table->string('alert_type'); // device_offline, interface_down, high_cpu, high_ram, high_latency, high_util, health_failure
            $table->string('severity')->default('warning'); // info, warning, critical
            $table->text('message');
            $table->string('status')->default('open'); // open, acknowledged, resolved
            $table->decimal('metric_value', 15, 2)->nullable();
            $table->decimal('threshold', 15, 2)->nullable();
            $table->string('interface')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['device_id', 'status']);
            $table->index(['alert_type', 'status']);
        });

        Schema::create('network_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_a_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignId('device_b_id')->constrained('routers')->cascadeOnDelete();
            $table->string('interface_a')->nullable();
            $table->string('interface_b')->nullable();
            $table->string('media')->default('fiber'); // fiber, copper, wireless
            $table->string('status')->default('up'); // up, down, degraded
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['device_a_id', 'device_b_id', 'interface_a', 'interface_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_links');
        Schema::dropIfExists('network_alerts');
        Schema::dropIfExists('device_metrics');

        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'device_type', 'model', 'vendor', 'snmp_community',
                'snmp_port', 'snmp_version', 'location_lat', 'location_lng',
            ]);
        });
    }
};

