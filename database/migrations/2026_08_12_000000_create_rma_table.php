<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RMA ledger — Returns / Replacements / Repairs for the Field Operations
     * domain. Mirrors the WorkOrder lifecycle pattern: each RMA moves through
     * requested -> approved -> processing -> completed (with reject/cancel exits).
     */
    public function up(): void
    {
        Schema::create('rmas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('rma_number')->unique();

            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('customer_equipment_id')->nullable()->constrained('customer_equipment')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();

            $table->string('type')->default('replacement');      // return | replacement | repair
            $table->string('priority')->default('normal');      // low | normal | high | urgent
            $table->string('status')->default('requested');     // requested | approved | rejected | processing | completed | cancelled

            $table->text('reason')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('tracking_number')->nullable();

            $table->dateTime('expected_return_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmas');
    }
};
