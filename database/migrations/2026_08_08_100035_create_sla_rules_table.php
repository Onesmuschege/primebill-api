<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sla_policy_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('condition_type'); // ticket_age, response_overdue, resolution_overdue, priority_match
            $table->json('conditions')->nullable(); // { "field": "status", "operator": "!=", "value": "resolved" }
            $table->json('actions')->nullable(); // { "escalate": true, "notify": ["manager"], "change_priority": 2 }
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'sla_policy_id', 'is_active']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_rules');
    }
};
