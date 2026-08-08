<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('assigned_to_type'); // client, user, work_order
            $table->unsignedBigInteger('assigned_to_id');
            $table->string('status')->default('active'); // active, returned, transferred, lost
            $table->date('assigned_date');
            $table->date('returned_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'inventory_item_id', 'status']);
            $table->index(['tenant_id', 'assigned_to_type', 'assigned_to_id']);
            $table->index(['tenant_id', 'assigned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_assignments');
    }
};
