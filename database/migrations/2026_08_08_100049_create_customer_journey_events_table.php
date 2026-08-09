<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_journey_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('event'); // registered, subscribed, activated, paid, suspended, upgraded, downgraded, churned
            $table->string('category')->nullable(); // lifecycle, billing, support, service
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // previous_status, new_status, trigger, etc.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Staff who triggered it
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'event']);
            $table->index(['tenant_id', 'category', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_journey_events');
    }
};
