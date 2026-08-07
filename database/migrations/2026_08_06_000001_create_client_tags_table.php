<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#3B82F6'); // hex color
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
            $table->index('tenant_id');
        });

        Schema::create('client_tag_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'client_id', 'client_tag_id']);
            $table->index(['tenant_id', 'client_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tag_assignments');
        Schema::dropIfExists('client_tags');
    }
};
