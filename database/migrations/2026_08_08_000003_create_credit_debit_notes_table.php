<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Credit Notes & Debit Notes.
 *
 * Credit notes reduce what a client owes (issued against an invoice, e.g.
 * for a discount correction, service credit, or returned service). Debit
 * notes increase what a client owes (e.g. late fees, equipment charges).
 *
 * Both carry their own number sequence, reference the original invoice,
 * and can be reversed. Every note posts a balanced ledger pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credit_note_number')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->enum('status', ['draft', 'issued', 'applied', 'reversed'])->default('draft');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('reference', 36)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('debit_note_number')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->enum('status', ['draft', 'issued', 'applied', 'reversed'])->default('draft');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('reference', 36)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
        Schema::dropIfExists('credit_notes');
    }
};
