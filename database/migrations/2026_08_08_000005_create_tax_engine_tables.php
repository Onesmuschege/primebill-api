<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 - Tax Engine.
 *
 * Replaces the flat calculateTax() with a proper multi-rate tax engine:
 *
 *  - tax_rates:      tenant-scoped tax rates (e.g. 16% VAT, 2% service levy).
 *  - invoice_tax_lines: per-invoice tax breakdown lines. Each line references
 *                    a tax rate and stores the taxable base + computed amount.
 *
 * Invoice totals are derived from the sum of tax lines, never a single
 * flat percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->decimal('rate', 6, 3)->default(0);
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('invoice_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tax_name');
            $table->string('tax_code', 20)->nullable();
            $table->decimal('rate', 6, 3)->default(0);
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'tax_rate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_tax_lines');
        Schema::dropIfExists('tax_rates');
    }
};
