<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warranty catalogue. The Warranty model (app\Models\Warranty) already
     * exists, but this is the first migration that materialises the table,
     * so the model currently has no backing schema.
     */
    public function up(): void
    {
        if (Schema::hasTable('warranties')) {
            return;
        }

        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('warranty_number')->unique();

            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('customer_equipment_id')->nullable()->constrained('customer_equipment')->nullOnDelete();
                        $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('provider');
            $table->string('status')->default('active');         // active | expired | claimed
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type')->nullable();
            $table->text('coverage_details')->nullable();
            $table->text('terms')->nullable();
            $table->date('claim_date')->nullable();
            $table->text('claim_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
