<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_queue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // STANDARD, PREMIUM, ENTERPRISE, EMERGENCY
            $table->text('description')->nullable();
            $table->integer('priority_level')->default(0); // 0=low, 1=medium, 2=high, 3=critical
            $table->integer('response_time_minutes')->nullable(); // First response target
            $table->integer('resolution_time_minutes')->nullable(); // Resolution target
            $table->json('business_hours')->nullable(); // e.g. {"mon":["08:00","18:00"],...}
            $table->boolean('apply_on_weekends')->default(false);
            $table->boolean('apply_on_holidays')->default(false);
            $table->boolean('escalation_enabled')->default(true);
            $table->integer('escalation_after_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'department_id', 'is_active']);
            $table->index(['tenant_id', 'priority_level']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
