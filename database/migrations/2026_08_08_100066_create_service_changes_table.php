<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // upgrade, downgrade, plan_migration, speed_change, configuration_change
            $table->string('from_plan_id')->nullable();
            $table->string('to_plan_id')->nullable();
            $table->decimal('from_speed_download', 10, 2)->nullable();
            $table->decimal('from_speed_upload', 10, 2)->nullable();
            $table->decimal('to_speed_download', 10, 2)->nullable();
            $table->decimal('to_speed_upload', 10, 2)->nullable();
            $table->string('from_service_type')->nullable();
            $table->string('to_service_type')->nullable();
            $table->json('from_config')->nullable();
            $table->json('to_config')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed, cancelled, failed
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_account_id', 'type', 'status']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_changes');
    }
};
