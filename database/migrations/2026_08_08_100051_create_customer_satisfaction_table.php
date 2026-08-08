<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_satisfaction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // csat, nps, service_rating, survey
            $table->string('source')->nullable(); // ticket, interaction, survey, automatic
            $table->integer('score'); // CSAT 1-5, NPS 0-10, etc.
            $table->integer('max_score')->default(10);
            $table->string('category')->nullable(); // support, service, billing, network
            $table->text('comment')->nullable();
            $table->json('responses')->nullable(); // Detailed survey responses
            $table->json('metadata')->nullable(); // survey_id, campaign_id, etc.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'type']);
            $table->index(['tenant_id', 'category', 'score']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_satisfaction');
    }
};
