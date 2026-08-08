<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 - Invoice discount columns.
 *
 * Adds discount fields to invoices so the discount engine can apply
 * line-level discounts while keeping the invoice total authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->default(0)->after('amount');
            $table->decimal('subtotal', 12, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount', 'subtotal']);
        });
    }
};
