<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->integer('points');                          // positive = earned, negative = redeemed
            $table->enum('type', ['earned', 'redeemed', 'expired', 'adjustment']);
            $table->string('reason');                           // human readable e.g. "Payment INV-001"
            $table->nullableMorphs('reference');                // reference_type + reference_id — links to Invoice, Payment, etc.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->integer('loyalty_points_balance')->default(0)->after('status');
            $table->string('referral_code')->nullable()->unique()->after('loyalty_points_balance');
            $table->foreignId('referred_by')->nullable()->after('referral_code')
                ->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_points');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_balance', 'referral_code', 'referred_by']);
        });
    }
};