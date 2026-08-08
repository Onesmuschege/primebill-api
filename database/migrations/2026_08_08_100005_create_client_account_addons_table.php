<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_addon_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active, suspended, cancelled
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('billing_type')->default('monthly');
            $table->json('configuration')->nullable(); // Customer-specific config overrides
            $table->date('activated_at')->nullable();
            $table->date('suspended_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_account_id', 'status']);
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'service_addon_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->unique(['tenant_id', 'client_account_id', 'service_addon_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_addons');
    }
};
