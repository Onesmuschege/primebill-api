<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // survey, feedback, complaint, suggestion
            $table->string('category')->nullable(); // service, support, billing, network
            $table->integer('rating')->nullable(); // 1-5 or 1-10
            $table->string('subject')->nullable();
            $table->text('feedback')->nullable();
            $table->json('responses')->nullable(); // Survey question responses
            $table->json('attachments')->nullable();
            $table->string('status')->default('new'); // new, reviewed, resolved, closed
            $table->text('response')->nullable(); // Staff response
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'type']);
            $table->index(['tenant_id', 'category', 'status']);
            $table->index(['tenant_id', 'rating']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_feedback');
    }
};
