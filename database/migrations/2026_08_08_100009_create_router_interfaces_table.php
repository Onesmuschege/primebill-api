<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // ether1, ether2, sfp1, etc.
            $table->string('type'); // ethernet, vlan, bridge, wireless, sfp
            $table->string('status')->default('disabled'); // enabled, disabled, disabled_by_admin
            $table->ipAddress('ip_address')->nullable();
            $table->string('mac_address')->nullable();
            $table->integer('vlan_id')->nullable();
            $table->string('description')->nullable();
            $table->json('metrics')->nullable(); // Latest metrics snapshot
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'router_id', 'name']);
            $table->index(['tenant_id', 'router_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_interfaces');
    }
};
