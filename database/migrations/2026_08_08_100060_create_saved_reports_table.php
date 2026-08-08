<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // revenue, customers, network, inventory, sla
            $table->string('type'); // financial, customer, network, operations, support
            $table->text('description')->nullable();
            $table->json('filters')->nullable(); // date_range, client_id, status, etc.
            $table->json('columns')->nullable(); // selected columns
            $table->json('grouping')->nullable(); // group_by fields
            $table->json('sorting')->nullable(); // sort_by, sort_direction
            $table->json('visualization')->nullable(); // chart_type, options
            $table->boolean('is_public')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'type']);
            $table->index(['tenant_id', 'is_public', 'is_favorite']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
