<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 — Fiber / OLT Management.
     *
     * OLT devices, PON ports, ONT/ONUs, and the fiber infrastructure
     * (routes, splitters, cabinets, distribution points). Links
     * OLT → PON → ONT → ClientAccount → Client.
     */
    public function up(): void
    {
        // ── OLT Devices ────────────────────────────────────────────────────
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('vendor')->default('huawei'); // huawei, zte, fiberhome, vsol
            $table->string('model')->nullable();
            $table->string('ip_address');
$table->string('username')->nullable();
            // Encrypted at rest (TEXT).
            $table->text('password')->nullable();
            // SNMP community — encrypted at rest, so TEXT.
            $table->text('snmp_community')->nullable();
            $table->string('status')->default('online'); // online, offline, maintenance
            $table->string('location')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // ── PON Ports (on an OLT) ──────────────────────────────────────────
        Schema::create('pon_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. gpon-0/1/0
            $table->string('technology')->default('gpon'); // gpon, xgpon, gpon/xgpon
            $table->string('status')->default('active'); // active, inactive, faulty
            $table->integer('max_onts')->default(64);
            $table->integer('registered_onts')->default(0);
            $table->timestamps();

            $table->unique(['olt_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        // ── ONT / ONU ──────────────────────────────────────────────────────
        Schema::create('onts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pon_port_id')->constrained()->cascadeOnDelete();
            $table->string('serial'); // vendor serial e.g. HWTC12345678
            $table->string('mac_address')->nullable();
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('firmware')->nullable();
$table->decimal('rx_signal', 8, 2)->nullable(); // dBm
            $table->decimal('tx_signal', 8, 2)->nullable(); // dBm
            $table->string('status')->default('offline'); // online, offline, provisioning, faulty
            $table->timestamp('last_seen')->nullable();
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial']);
            $table->index(['tenant_id', 'status']);
            $table->index(['olt_id', 'pon_port_id']);
        });

        // ── Fiber Routes ───────────────────────────────────────────────────
        Schema::create('fiber_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->nullable();
            $table->string('destination')->nullable();
            $table->decimal('length_km', 10, 3)->nullable();
            $table->string('cable_type')->nullable(); // aerial, underground, duct
            $table->string('status')->default('active'); // active, planned, maintenance
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // ── Fiber Splitters ────────────────────────────────────────────────
        Schema::create('fiber_splitters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('split_ratio')->default('1:8'); // 1:4, 1:8, 1:16, 1:32, 1:64
            $table->string('location')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // ── Cabinets ───────────────────────────────────────────────────────
        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('fiber'); // fiber, power, distribution
            $table->string('location')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('status')->default('active');
            $table->string('capacity')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // ── Distribution Points ────────────────────────────────────────────
        Schema::create('distribution_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('fiber_hub'); // fiber_hub, splice_tray, drop_point
            $table->string('location')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_points');
        Schema::dropIfExists('cabinets');
        Schema::dropIfExists('fiber_splitters');
        Schema::dropIfExists('fiber_routes');
        Schema::dropIfExists('onts');
        Schema::dropIfExists('pon_ports');
        Schema::dropIfExists('olts');
    }
};
