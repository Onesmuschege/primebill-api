<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 - Refunds.
 *
 * A refund is issued against a completed Payment. It is idempotent
 * (IdempotencyService) and posts a balanced ledger reversal pair so the
 * original payment credit is reversed without ever duplicating entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('refund_number')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->enum('method', ['mpesa', 'cash', 'bank', 'wallet', 'other'])->default('other');
            $table->string('reference')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed'])->default('pending');
            $table->string('reason')->nullable();
            $table->string('reference_uuid', 36)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['payment_id', 'status']);
            $table->index('reference_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
