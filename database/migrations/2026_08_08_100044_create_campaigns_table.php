<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // WELCOME, PROMOTION, OUTAGE, MAINTENANCE
            $table->string('type'); // email, sms, whatsapp, multi
            $table->string('category'); // marketing, support, billing, system
            $table->text('subject')->nullable();
            $table->text('content'); // Template content
            $table->text('content_plain')->nullable();
            $table->json('target_audience')->nullable(); // { "segments": ["all", "active"], "filters": {...} }
            $table->json('attachments')->nullable();
            $table->string('status')->default('draft'); // draft, scheduled, sending, sent, paused, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->string('priority')->default('normal'); // low, normal, high
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
