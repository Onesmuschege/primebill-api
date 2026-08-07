<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('work_order_number')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('installation'); // installation, repair, relocation, maintenance, survey
            $table->string('status')->default('scheduled'); // scheduled, dispatched, in_progress, completed, cancelled
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('photos')->nullable(); // URLs to photos
            $table->json('customer_signature')->nullable(); // Base64 signature
            $table->text('completion_notes')->nullable();
            $table->decimal('completion_latitude', 10, 8)->nullable();
            $table->decimal('completion_longitude', 11, 8)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'assigned_to']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index('work_order_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
