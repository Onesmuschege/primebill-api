<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('annual_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_trial_available')->default(true);
            $table->unsignedInteger('trial_days')->default(14);
            $table->unsignedInteger('grace_days')->default(7);
            $table->json('features')->nullable();
            $table->unsignedInteger('max_clients')->default(0);
            $table->unsignedInteger('max_users')->default(0);
            $table->unsignedInteger('max_routers')->default(0);
            $table->unsignedInteger('storage_quota_gb')->default(0);
            $table->unsignedInteger('api_calls_per_month')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
