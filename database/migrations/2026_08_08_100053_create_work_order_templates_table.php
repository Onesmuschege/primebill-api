<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // FIBER_INSTALL, REPAIR, UPGRADE, MAINTENANCE
            $table->text('description')->nullable();
            $table->string('type'); // installation, repair, maintenance, inspection
            $table->string('category')->nullable(); // fiber, wireless, copper, general
            $table->integer('estimated_duration')->nullable(); // minutes
            $table->json('required_skills')->nullable(); // ["fiber_splicing", "router_config"]
            $table->json('required_equipment')->nullable(); // ["olt", "ont", "splicer"]
            $table->json('checklist')->nullable(); // Pre-defined checklist items
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'type']);
            $table->index(['tenant_id', 'category', 'is_active']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_templates');
    }
};
