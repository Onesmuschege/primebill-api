<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ont_signal_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ont_id')->constrained()->cascadeOnDelete();
            $table->decimal('rx_power', 8, 2)->nullable(); // dBm
            $table->decimal('tx_power', 8, 2)->nullable(); // dBm
            $table->decimal('temperature', 8, 2)->nullable(); // Celsius
            $table->decimal('voltage', 8, 2)->nullable(); // Volts
            $table->decimal('bias_current', 8, 2)->nullable(); // mA
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'ont_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ont_signal_history');
    }
};
