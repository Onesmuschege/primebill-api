<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiber_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ont_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pon_port_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('olt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiber_route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiber_splitter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('distribution_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('planned'); // planned, installed, active, suspended, terminated
            $table->string('connection_type')->default('ftth'); // ftth, fttb, ftte, dedicated
            $table->integer('port_number')->nullable(); // PON port number
            $table->string('serial_number')->nullable(); // ONT serial if applicable
            $table->string('mac_address')->nullable(); // ONT MAC
            $table->json('technical_details')->nullable(); // split ratio, distance, etc.
            $table->date('installation_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'client_account_id', 'status']);
            $table->index(['tenant_id', 'ont_id', 'status']);
            $table->index(['tenant_id', 'olt_id', 'status']);
            $table->index(['tenant_id', 'pon_port_id', 'status']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_connections');
    }
};
