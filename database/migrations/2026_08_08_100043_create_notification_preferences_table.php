<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('notifiable_type'); // client, user
            $table->unsignedBigInteger('notifiable_id');
            $table->json('email_enabled')->nullable(); // { "invoice": true, "payment": true, "ticket": true }
            $table->json('sms_enabled')->nullable();
            $table->json('whatsapp_enabled')->nullable();
            $table->json('push_enabled')->nullable();
            $table->json('in_app_enabled')->nullable();
            $table->json('quiet_hours')->nullable(); // { "start": "22:00", "end": "08:00", "timezone": "Africa/Nairobi" }
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'notifiable_type', 'notifiable_id']);
            $table->unique(['tenant_id', 'notifiable_type', 'notifiable_id', 'deleted_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
