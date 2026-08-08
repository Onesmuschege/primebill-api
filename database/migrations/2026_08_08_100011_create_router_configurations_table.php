<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('label')->nullable(); // human-friendly label
            $table->json('configuration')->nullable(); // full router config
            $table->json('interfaces')->nullable();
            $table->json('firewall_rules')->nullable();
            $table->json('queue_rules')->nullable();
            $table->json('routing_rules')->nullable();
            $table->json('variables')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'router_id', 'version']);
            $table->index(['tenant_id', 'router_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_configurations');
    }
};
