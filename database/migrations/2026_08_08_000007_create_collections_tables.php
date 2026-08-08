<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 - Collections Workflow.
 *
 *  - payment_plans:      installment plans that split an invoice balance into
 *                        scheduled payments.
 *  - payment_plan_installments: individual scheduled installments.
 *  - dunning_steps:      configurable dunning escalation steps (email, SMS,
 *                        call, suspend) with day offsets from the due date.
 *  - dunning_runs:       per-client execution history of a dunning step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->enum('status', ['active', 'completed', 'cancelled', 'defaulted'])->default('active');
            $table->unsignedInteger('installment_count')->default(1);
            $table->string('frequency', 20)->default('monthly'); // weekly, biweekly, monthly
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('payment_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'overdue', 'skipped'])->default('pending');
            $table->timestamp('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['payment_plan_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('dunning_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sequence')->default(1);
            $table->enum('action', ['email', 'sms', 'call', 'suspend', 'escalate'])->default('email');
            $table->unsignedInteger('days_after_due')->default(0);
            $table->string('template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('dunning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dunning_step_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->timestamp('executed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_runs');
        Schema::dropIfExists('dunning_steps');
        Schema::dropIfExists('payment_plan_installments');
        Schema::dropIfExists('payment_plans');
    }
};
