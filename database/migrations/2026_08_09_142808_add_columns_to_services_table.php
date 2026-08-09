<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn(['name', 'description', 'price', 'setup_fee', 'status']);
        });
    }
};
