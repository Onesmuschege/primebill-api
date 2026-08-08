<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete(); // null for platform-wide
            $table->string('event'); // login_failed, login_success, password_changed, mfa_enabled, suspicious_activity, etc.
            $table->string('severity')->default('info'); // info, warning, error, critical
            $table->string('category')->nullable(); // authentication, authorization, data_access, system
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // ip_address, user_agent, location, etc.
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('source')->nullable(); // web, api, mobile, system
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'event', 'severity']);
            $table->index(['tenant_id', 'category', 'created_at']);
            $table->index(['tenant_id', 'is_resolved', 'created_at']);
            $table->index(['tenant_id', 'ip_address', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
