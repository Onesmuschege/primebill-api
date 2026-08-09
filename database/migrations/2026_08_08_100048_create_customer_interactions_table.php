<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // call, email, visit, sms, whatsapp, support_interaction, sales_call
            $table->string('direction')->nullable(); // inbound, outbound
            $table->string('status')->default('completed'); // scheduled, completed, cancelled, missed
            $table->string('subject')->nullable();
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable(); // duration, outcome, follow_up_required, etc.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Staff member
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'type']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interactions');
    }
};
