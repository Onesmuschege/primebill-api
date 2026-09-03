<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial Transaction Identity (Core ISP Gate — Section 9).
 *
 * An invoice must be able to name the exact client service (ClientAccount)
 * it charges, so that a payment can never accidentally renew the wrong
 * service when a customer owns multiple connections (Section 28).
 *
 * Before this migration invoices only carried `client_id`, forcing
 * PaymentService::extendClientAccount() to guess the service (first active
 * account) — which silently renews the wrong connection for multi-service
 * customers.
 *
 * `client_account_id` is nullable so that legacy/aggregate invoices (e.g.
 * an invoice covering several services) still work; the renewal logic falls
 * back to the previous first-account behaviour only for unlinked invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('client_account_id')
                ->nullable()
                ->after('client_id');

            $table->foreign('client_account_id')
                ->references('id')
                ->on('client_accounts')
                ->nullOnDelete();

            $table->index(['client_id', 'client_account_id'], 'invoices_client_account_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_client_account_idx');
            $table->dropForeign(['client_account_id']);
            $table->dropColumn('client_account_id');
        });
    }
};