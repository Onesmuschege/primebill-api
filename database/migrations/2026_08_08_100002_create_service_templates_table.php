<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('service_type'); // pppoe, hotspot, static_ip, fiber, enterprise, dedicated
            $table->json('plan_defaults')->nullable();
            $table->json('provisioning_profile')->nullable();
            $table->json('qos_profile')->nullable();
            $table->json('ip_requirements')->nullable();
            $table->json('vlan_requirements')->nullable();
            $table->json('radius_profile')->nullable();
            $table->json('router_profile')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'service_type', 'is_active']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_templates');
    }
};
