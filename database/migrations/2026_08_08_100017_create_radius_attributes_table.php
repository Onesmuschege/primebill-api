<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radius_profile_id')->nullable()->constrained('radius_profiles')->cascadeOnDelete();
            $table->string('name'); // Framed-Protocol, Framed-IP-Address, etc.
            $table->string('vendor')->nullable(); // Vendor-Specific attribute vendor
            $table->string('type'); // string, integer, ipaddr, date, boolean
            $table->text('value'); // The actual attribute value
            $table->string('opcode')->default('='); // =, :=, +=, etc.
            $table->integer('priority')->default(0);
            $table->boolean('is_encrypted')->default(false);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'radius_profile_id']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_attributes');
    }
};
