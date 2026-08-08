<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // invoice, payment_receipt, suspension, restoration, outage, maintenance, ticket_update, welcome
            $table->string('type'); // email, sms, whatsapp, push, in_app
            $table->string('category'); // billing, support, maintenance, marketing, system
            $table->text('subject')->nullable();
            $table->text('content'); // Template content with variables
            $table->text('content_plain')->nullable();
            $table->json('variables')->nullable(); // Available template variables
            $table->json('attachments')->nullable();
            $table->string('priority')->default('normal'); // low, normal, high
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'is_active']);
            $table->index(['tenant_id', 'type', 'category']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_templates');
    }
};
