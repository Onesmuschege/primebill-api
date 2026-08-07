<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('label');
            $table->string('type')->default('text'); // text, textarea, number, date, select, checkbox, url
            $table->text('options')->nullable(); // JSON for select/checkbox options
            $table->boolean('is_required')->default(false);
            $table->boolean('is_visible_on_portal')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('client_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_custom_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'client_id', 'client_custom_field_id']);
            $table->index(['tenant_id', 'client_custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_custom_field_values');
        Schema::dropIfExists('client_custom_fields');
    }
};
