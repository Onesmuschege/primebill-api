<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable(); // revenue_chart, customer_stats, network_status, ticket_summary
            $table->string('type'); // chart, metric, table, map, list
            $table->string('chart_type')->nullable(); // line, bar, pie, area, number, gauge
            $table->string('data_source')->nullable(); // revenue, customers, network, tickets
            $table->json('query')->nullable(); // query parameters
            $table->json('options')->nullable(); // chart options, colors, legends
            $table->json('layout')->nullable(); // position, size, colspan, rowspan
            $table->integer('sort_order')->default(0);
            $table->integer('refresh_interval')->nullable(); // seconds
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'dashboard_id', 'sort_order']);
            $table->index(['tenant_id', 'code', 'type']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
