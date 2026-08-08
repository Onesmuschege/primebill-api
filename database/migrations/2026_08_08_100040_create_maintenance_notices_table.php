<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('scheduled'); // scheduled, emergency, completed
            $table->text('summary')->nullable();
            $table->text('description');
            $table->text('impact_description')->nullable();
            $table->json('affected_services')->nullable(); // pppoe, hotspot, fiber, etc.
            $table->json('affected_areas')->nullable(); // regions, zones
            $table->string('severity')->default('medium'); // low, medium, high, critical
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('send_notification')->default(true);
            $table->json('notifications_sent')->nullable();
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type', 'is_published']);
            $table->index(['tenant_id', 'severity', 'starts_at']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_notices');
    }
};
