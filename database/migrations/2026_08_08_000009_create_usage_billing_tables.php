<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 - Usage Billing.
 *
 * Tracks data-usage overage for billing. Overage is computed from
 * RadiusSession / NetworkTraffic bytes against the plan's FUP limit, then
 * posted as an invoice line + ledger debit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_billing_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_period', 7)->nullable(); // YYYY-MM
            $table->bigInteger('bytes_used')->default(0);
            $table->bigInteger('bytes_included')->default(0);
            $table->bigInteger('bytes_overage')->default(0);
            $table->decimal('rate_per_gb', 12, 2)->default(0);
            $table->decimal('overage_amount', 12, 2)->default(0);
            $table->enum('status', ['pending', 'invoiced', 'waived'])->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'billing_period']);
            $table->index(['client_account_id', 'billing_period']);
            $table->index(['status', 'billing_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_billing_records');
    }
};
