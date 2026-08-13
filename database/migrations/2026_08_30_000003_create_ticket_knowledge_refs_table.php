<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Knowledge references attached to service-desk tickets. Operators mark
     * the KB articles they used to resolve a ticket, so the same fix is one
     * click away next time (Release 4 — "knowledge references").
     *
     * A dedicated tenant-scoped model (rather than a plain pivot) records who
     * attached the reference and an optional note about how it applied.
     */
    public function up(): void
    {
        Schema::create('ticket_knowledge_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('knowledge_base_article_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id']);
            $table->unique(['ticket_id', 'knowledge_base_article_id'], 'ticket_kb_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_knowledge_refs');
    }
};
