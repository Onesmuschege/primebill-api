<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('note');
            $table->string('type')->default('general'); // general, call, meeting, support
            $table->string('priority')->default('normal'); // low, normal, high
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'created_by']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
    }
};
