<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network Core — Phase A Foundation
 *
 * Adds the service-centric domain model on top of the existing
 * client_accounts table:
 *
 *   1. Extends client_accounts with service lifecycle + access method + NAS
 *   2. devices               — customer devices bound to a service
 *   3. service_profiles      — RADIUS attribute mapping (plan → attributes)
 *   4. network_events        — auditable network event trail
 *   5. radius_control_logs   — CoA/Disconnect request tracking
 *   6. Extends routers       — NAS/RADIUS configuration fields
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────
        // 1. Extend client_accounts — service lifecycle & access method
        // ─────────────────────────────────────────────────────────────
        Schema::table('client_accounts', function (Blueprint $table) {
            // Access method (strategy selector)
            $table->string('access_method')->default('pppoe')
                ->comment('pppoe|hotspot|static_ip|dhcp');

            // NAS association
            $table->foreignId('nas_id')->nullable()->after('router_id')
                ->comment('NAS/RADIUS device id (routers.id)');

            // Service lifecycle state machine
            $table->string('service_state')->default('PENDING')
                ->comment('PENDING|PROVISIONING|ACTIVE|PAST_DUE|GRACE_PERIOD|SUSPENDED|TERMINATED');

            // Timestamps for lifecycle transitions
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('terminated_at')->nullable();

            // Entitlement tracking
            $table->timestamp('entitled_until')->nullable()
                ->comment('When the service entitlement expires (billing-based)');
            $table->boolean('is_entitled')->default(false)
                ->comment('Current billing entitlement state');

            // Bandwidth policy
            $table->string('rate_limit_policy')->nullable()
                ->comment('Current applied rate limit policy name');

            // Service profile reference
            $table->foreignId('service_profile_id')->nullable()
                ->comment('ServiceProfile used for RADIUS attribute generation');

            // Indices for lifecycle queries
            $table->index(['service_state', 'is_entitled']);
            $table->index('access_method');
            $table->index('entitled_until');
        });

        // ─────────────────────────────────────────────────────────────
        // 2. devices — customer devices bound to services
        // ─────────────────────────────────────────────────────────────
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->nullable()
                ->constrained('client_accounts')->nullOnDelete();
            $table->foreignId('nas_id')->nullable()
                ->comment('NAS/router where device was first seen');

            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable()
                ->comment('router|phone|tablet|laptop|desktop|tv|other');
            $table->string('vendor')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->string('status')->default('active')
                ->comment('active|inactive|blocked');

            $table->timestamps();

            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'client_account_id']);
            $table->index(['tenant_id', 'mac_address']);
            $table->index(['tenant_id', 'status']);
        });

        // ─────────────────────────────────────────────────────────────
        // 3. service_profiles — RADIUS attribute mapping layer
        //    (Plan → ServiceProfile → RADIUS Attributes)
        // ─────────────────────────────────────────────────────────────
        Schema::create('service_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();

            // Bandwidth policy (Kbps)
            $table->integer('download_speed')->default(1024);
            $table->integer('upload_speed')->default(512);
            $table->integer('burst_down')->nullable();
            $table->integer('burst_up')->nullable();

            // Session policy
            $table->integer('session_timeout')->nullable()
                ->comment('RADIUS Session-Timeout in seconds');
            $table->integer('idle_timeout')->nullable()
                ->comment('RADIUS Idle-Timeout in seconds');
            $table->integer('simultaneous_sessions')->default(1)
                ->comment('Simultaneous-Use limit');

            // Data policy
            $table->bigInteger('data_limit_bytes')->nullable()
                ->comment('FUP data limit in bytes');
            $table->integer('fup_download_speed')->nullable();
            $table->integer('fup_upload_speed')->nullable();

            // RADIUS attribute generation
            $table->json('custom_radius_attributes')->nullable()
                ->comment('Additional RADIUS check/reply attributes');

            $table->string('service_type')->default('pppoe')
                ->comment('pppoe|hotspot|static_ip|dhcp');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'service_type']);
            $table->index(['tenant_id', 'is_active']);
        });

        // ─────────────────────────────────────────────────────────────
        // 4. network_events — auditable network event trail
        // ─────────────────────────────────────────────────────────────
        Schema::create('network_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('severity')->default('info')
                ->comment('info|warning|error|critical');

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()
                ->constrained('client_accounts')->nullOnDelete();
            $table->foreignId('nas_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->foreignId('radius_session_id')->nullable()->constrained('radius_sessions')->nullOnDelete();

            $table->string('message');
            $table->json('context')->nullable();
            $table->string('source')->default('system')
                ->comment('system|radius|mikrotik|portal|billing|fup');

            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'client_account_id']);
            $table->index(['tenant_id', 'nas_id']);
            $table->index(['tenant_id', 'severity']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        // ─────────────────────────────────────────────────────────────
        // 5. radius_control_logs — CoA/Disconnect request tracking
        // ─────────────────────────────────────────────────────────────
        Schema::create('radius_control_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('action')
                ->comment('disconnect_session|change_rate_limit|change_session_timeout|apply_policy|coa');
            $table->foreignId('radius_session_id')->nullable()
                ->constrained('radius_sessions')->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()
                ->constrained('client_accounts')->nullOnDelete();
            $table->foreignId('nas_id')->nullable()->constrained('routers')->nullOnDelete();

            $table->string('username')->nullable();
            $table->string('session_id')->nullable();

            $table->string('status')->default('pending')
                ->comment('pending|sent|completed|failed|cancelled');
            $table->string('result')->nullable();

            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error')->nullable();

            $table->integer('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_account_id']);
            $table->index(['tenant_id', 'radius_session_id']);
            $table->index(['tenant_id', 'nas_id']);
        });

        // ─────────────────────────────────────────────────────────────
        // 6. Extend routers — NAS/RADIUS configuration fields
        // ─────────────────────────────────────────────────────────────
        Schema::table('routers', function (Blueprint $table) {
            $table->string('radius_ip')->nullable()
                ->comment('IP address used for RADIUS packets (typically router LAN IP)');
            $table->integer('radius_auth_port')->default(1812);
            $table->integer('radius_acct_port')->default(1813);
            $table->integer('coa_port')->default(3799)
                ->comment('Change-of-Authorization / Disconnect port');
            $table->string('radius_secret_encrypted')->nullable()
                ->comment('RADIUS shared secret (encrypted at rest)');
            $table->string('nas_identifier')->nullable()
                ->comment('RADIUS NAS-Identifier');
            $table->string('nas_type')->default('mikrotik')
                ->comment('mikrotik|freeradius|other');
            $table->string('routeros_version')->nullable();
            $table->json('capabilities')->nullable()
                ->comment('JSON list of supported features: pppoe, hotspot, dhcp, static_ip, coa, disconnect');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_control_logs');
        Schema::dropIfExists('network_events');
        Schema::dropIfExists('service_profiles');
        Schema::dropIfExists('devices');

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'access_method', 'nas_id', 'service_state',
                'provisioned_at', 'suspended_at', 'restored_at', 'terminated_at',
                'entitled_until', 'is_entitled', 'rate_limit_policy',
                'service_profile_id',
            ]);
        });

        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'radius_ip', 'radius_auth_port', 'radius_acct_port',
                'coa_port', 'radius_secret_encrypted', 'nas_identifier',
                'nas_type', 'routeros_version', 'capabilities',
            ]);
        });
    }
};
