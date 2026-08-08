<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique(); // pppoe, hotspot, static_ip, fiber, enterprise, dedicated
            $table->text('description')->nullable();
            $table->json('provisioning_rules')->nullable();
            $table->json('radius_attributes')->nullable();
            $table->json('router_commands')->nullable();
            $table->json('ip_allocation_rules')->nullable();
            $table->json('vlan_rules')->nullable();
            $table->json('qos_rules')->nullable();
            $table->json('validation_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'is_active']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_profiles');
    }
};
