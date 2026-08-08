<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_command_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->text('command'); // The command executed
            $table->string('source')->default('system'); // system, user, api
            $table->string('status')->default('pending'); // pending, success, failed, timeout
            $table->text('result')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->json('context')->nullable(); // Additional context
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Who triggered it
            $table->ipAddress('ip_address')->nullable();
            $table->userAgent()->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'router_id', 'status']);
            $table->index(['tenant_id', 'router_id', 'created_at']);
            $table->index(['tenant_id', 'source', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_command_logs');
    }
};
