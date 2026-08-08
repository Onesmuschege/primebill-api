<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise IPAM (IP Address Management) — IPv4/IPv6 pools, subnets,
 * allocations (with history), reservations, DHCP pools/leases, VLANs and
 * VLAN assignments. Every table is tenant-scoped and audited.
 */
return new class extends Migration
{
public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | vlans — L2 segmentation definitions.
        | Created first because ip_pools/ip_subnets reference vlans via FK.
        |--------------------------------------------------------------------------
        */
        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->integer('vlan_id');         // 1-4094
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'vlan_id']);
            $table->index(['tenant_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | ip_pools — logical ranges of addresses available for allocation
        |--------------------------------------------------------------------------
        */
        Schema::create('ip_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('family', ['ipv4', 'ipv6'])->default('ipv4');
            $table->string('network');          // e.g. 192.168.10.0
            $table->integer('prefix');          // e.g. 24
            $table->string('gateway')->nullable();
            $table->string('dns_primary')->nullable();
            $table->string('dns_secondary')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('status')->default('active'); // active, disabled, exhausted
            $table->text('description')->nullable();
            $table->foreignId('vlan_id')->nullable()->constrained('vlans')->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'family']);
            $table->index(['tenant_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | ip_subnets — CIDR blocks carved out of a pool (or standalone)
        |--------------------------------------------------------------------------
        */
        Schema::create('ip_subnets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->enum('family', ['ipv4', 'ipv6'])->default('ipv4');
            $table->string('cidr');             // e.g. 192.168.10.0/24
            $table->string('network');
            $table->integer('prefix');
            $table->string('gateway')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('status')->default('active');
            $table->text('description')->nullable();
            $table->foreignId('vlan_id')->nullable()->constrained('vlans')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'family']);
            $table->index(['tenant_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | ip_allocations — addresses assigned to a service/client
        |--------------------------------------------------------------------------
        */
        Schema::create('ip_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ip_subnet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address');
            $table->enum('family', ['ipv4', 'ipv6'])->default('ipv4');
            $table->string('status')->default('allocated'); // allocated, reserved, released
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained('client_accounts')->nullOnDelete();
            $table->foreignId('vlan_id')->nullable()->constrained('vlans')->nullOnDelete();
            $table->string('mac_address')->nullable();
            $table->string('hostname')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // Conflict detection — an address may only be allocated once per tenant.
            $table->unique(['tenant_id', 'ip_address', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'client_account_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | ip_allocation_history — who/when/what for every allocation event
        |--------------------------------------------------------------------------
        */
        Schema::create('ip_allocation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_allocation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');           // allocated, released, reserved, changed
            $table->string('ip_address');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained('client_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ip_allocation_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | ip_reservations — addresses held for a specific client/device
        |--------------------------------------------------------------------------
        */
        Schema::create('ip_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ip_subnet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address');
            $table->enum('family', ['ipv4', 'ipv6'])->default('ipv4');
            $table->string('mac_address')->nullable();
            $table->string('hostname')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained('client_accounts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'ip_address']);
            $table->index(['tenant_id', 'client_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | dhcp_pools — DHCP service configuration per subnet/pool
        |--------------------------------------------------------------------------
        */
        Schema::create('dhcp_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ip_subnet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('range_start');
            $table->string('range_end');
            $table->string('gateway')->nullable();
            $table->string('dns_primary')->nullable();
            $table->string('dns_secondary')->nullable();
            $table->string('lease_time')->default('24h');
            $table->string('status')->default('active');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | dhcp_leases — live/known DHCP leases
        |--------------------------------------------------------------------------
        */
        Schema::create('dhcp_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dhcp_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address');
            $table->string('mac_address');
            $table->string('hostname')->nullable();
            $table->timestamp('lease_start')->nullable();
            $table->timestamp('lease_end')->nullable();
            $table->string('status')->default('active'); // active, expired, released
            $table->timestamps();

$table->index(['tenant_id', 'dhcp_pool_id']);
            $table->index(['tenant_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | vlan_assignments — VLAN attached to a pool, subnet or service
        |--------------------------------------------------------------------------
        */
        Schema::create('vlan_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vlan_id')->constrained()->cascadeOnDelete();
            $table->string('assignable_type')->nullable(); // ip_pool, ip_subnet, client_account
            $table->unsignedBigInteger('assignable_id')->nullable();
            $table->boolean('is_trunk')->default(false);
            $table->string('trunk_ports')->nullable();     // e.g. "ether1,ether2"
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vlan_id']);
            $table->index(['assignable_type', 'assignable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vlan_assignments');
        Schema::dropIfExists('vlans');
        Schema::dropIfExists('dhcp_leases');
        Schema::dropIfExists('dhcp_pools');
        Schema::dropIfExists('ip_reservations');
        Schema::dropIfExists('ip_allocation_history');
        Schema::dropIfExists('ip_allocations');
        Schema::dropIfExists('ip_subnets');
        Schema::dropIfExists('ip_pools');
    }
};

