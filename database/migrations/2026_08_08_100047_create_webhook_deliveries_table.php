<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event'); // payment.completed, ticket.created, etc.
            $table->string('status')->default('pending'); // pending, success, failed, timeout
            $table->integer('attempt_number')->default(1);
            $table->text('request_payload')->nullable();
            $table->text('response_body')->nullable();
            $table->string('response_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'webhook_id', 'status']);
            $table->index(['tenant_id', 'event', 'status']);
            $table->index(['tenant_id', 'next_retry_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
