<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();

            $table->foreignId('plan_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('redeemed_by')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();

            $table->enum('status', [
                'unused',
                'redeemed',
                'expired',
            ])->default('unused');

            $table->unsignedInteger('batch')->nullable()->index();

            $table->string('batch_label')->nullable();

            $table->timestamp('redeemed_at')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['plan_id', 'status']);
            $table->index(['batch', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};