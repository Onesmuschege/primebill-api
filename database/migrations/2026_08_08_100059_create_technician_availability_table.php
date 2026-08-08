<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Technician
            $table->date('available_date');
            $table->time('available_from')->nullable();
            $table->time('available_to')->nullable();
            $table->string('status')->default('available'); // available, busy, on_leave, unavailable
            $table->string('type')->default('regular'); // regular, overtime, on_call
            $table->integer('max_jobs')->nullable();
            $table->integer('assigned_jobs')->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'available_date', 'status']);
            $table->index(['tenant_id', 'available_date', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_availability');
    }
};
