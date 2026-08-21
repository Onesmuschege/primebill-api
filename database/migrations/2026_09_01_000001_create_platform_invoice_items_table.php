<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items for PrimeBill's invoices to its tenants — mirrors the
 * tenant-side invoice-item convention: each row is one billable line
 * (e.g. the subscription charge, then tax).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_items');
    }
};
