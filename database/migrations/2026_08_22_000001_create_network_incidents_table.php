<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium'); // low, medium, high, critical
            $table->string('status')->default('detected'); // detected, acknowledged, investigating, mitigating, resolved, closed
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Affected infrastructure
            $table->foreignId('affected_device_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->foreignId('affected_olt_id')->nullable()->constrained('olts')->nullOnDelete();
            $table->foreignId('affected_pon_port_id')->nullable()->constrained('pon_ports')->nullOnDelete();

            // People
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            // Resolution
            $table->text('root_cause')->nullable();
            $table->text('resolution')->nullable();
            $table->json('affected_services')->nullable(); // client_account_ids
            $table->integer('affected_customers_count')->default(0);
            $table->integer('duration_minutes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['tenant_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_incidents');
    }
};
