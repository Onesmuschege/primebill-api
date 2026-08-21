<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PrimeBill's own invoices to its tenants (its ISS tenants' PrimeBill
 * subscription), completely separate from the tenant-scoped billing engine
 * those tenants run for their own customers (Invoice / Payment models).
 *
 * Platform admins only — this table has a plain tenant_id, no BelongsToTenant
 * scope, matching PlatformInvoice's Eloquent model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'void'])->default('draft');
            $table->string('billing_period')->nullable(); // e.g. 2026-09
            $table->string('reference')->nullable();      // external payment ref
            $table->text('notes')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
