<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // executive, operations, finance, network, support
            $table->string('type'); // personal, team, tenant, system
            $table->text('description')->nullable();
            $table->json('layout')->nullable(); // widget positions, sizes, configurations
            $table->json('filters')->nullable(); // default filters
            $table->boolean('is_default')->default(false);
            $table->boolean('is_public')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'type']);
            $table->index(['tenant_id', 'is_default', 'sort_order']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboards');
    }
};
