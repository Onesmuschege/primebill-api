<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('communication_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel'); // email, sms, whatsapp, push, in_app
            $table->string('recipient_type'); // client, user, lead, prospect
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_address'); // email, phone, device_id
            $table->string('status')->default('pending'); // pending, sent, delivered, failed, bounced, rejected
            $table->text('subject')->nullable();
            $table->text('content')->nullable();
            $table->string('provider')->nullable(); // africas_talking, hostpinnacle, smtp, etc.
            $table->string('provider_reference')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'channel', 'status']);
            $table->index(['tenant_id', 'recipient_type', 'recipient_id']);
            $table->index(['tenant_id', 'provider_reference']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
