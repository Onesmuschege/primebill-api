<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_disconnect_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radius_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason'); // user_request, admin_disconnect, payment_overdue, policy_violation, system
            $table->text('reason_details')->nullable();
            $table->string('status')->default('pending'); // pending, sent, acknowledged, failed, timeout
            $table->text('response')->nullable();
            $table->string('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->userAgent()->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'radius_session_id', 'status']);
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'router_id', 'status']);
            $table->index(['tenant_id', 'reason', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_disconnect_requests');
    }
};
