<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_equipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('warranty_number')->nullable();
            $table->string('provider'); // manufacturer, supplier, extended
            $table->string('status')->default('active'); // active, expired, claimed, void
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type'); // standard, extended, onsite
            $table->text('coverage_details')->nullable();
            $table->text('terms')->nullable();
            $table->date('claim_date')->nullable();
            $table->text('claim_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'inventory_item_id', 'status']);
            $table->index(['tenant_id', 'customer_equipment_id', 'status']);
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'end_date']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
