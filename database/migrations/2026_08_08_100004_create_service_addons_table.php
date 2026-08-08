<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique(); // static_ip, extra_bandwidth, public_ip, managed_router, backup_link, premium_support
            $table->text('description')->nullable();
            $table->string('category'); // bandwidth, ip, hardware, support, reliability
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('KES');
            $table->string('billing_type')->default('monthly'); // monthly, yearly, one_time
            $table->json('configuration')->nullable(); // Technical configuration
            $table->json('provisioning_requirements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'is_active']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addons');
    }
};
