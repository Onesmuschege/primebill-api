<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // payment_callback, radius_event, network_alert, ticket_update
            $table->string('url'); // Target URL
            $table->string('method')->default('POST'); // POST, PUT, PATCH
            $table->json('headers')->nullable(); // Custom headers
            $table->json('authentication')->nullable(); // { "type": "bearer", "token": "..." }
            $table->json('payload_template')->nullable(); // Custom payload structure
            $table->json('events')->nullable(); // ["payment.completed", "ticket.created", ...]
            $table->string('status')->default('active'); // active, inactive, paused, failed
            $table->integer('timeout')->default(30); // seconds
            $table->integer('retry_count')->default(3);
            $table->integer('retry_delay')->default(60); // seconds
            $table->integer('failure_threshold')->default(5); // disable after X failures
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
