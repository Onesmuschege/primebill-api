<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_class');
            $table->string('type');
            $table->string('entity_class')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('processing'); // processing|done|failed|cancelled
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('tenant_id')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_failures', function (Blueprint $table) {
            $table->id();
            $table->string('event_class')->nullable();
            $table->string('event_type');
            $table->string('entity_class')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('job_class');
            $table->string('idempotency_key');
            $table->text('error');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->json('payload')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('tenant_id')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event_type');
            $table->json('action')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->foreignId('tenant_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_events');
        Schema::dropIfExists('automation_failures');
        Schema::dropIfExists('automation_rules');
    }
};
