<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\ClientAccount;

return new class extends Migration
{
    /**
     * SL2 — Administrative holds.
     *
     * Adds explicit suspension ownership to client_accounts so billing-driven
     * and administratively-driven suspensions are distinguishable:
     *
     *   - suspension_type: 'billing' | 'admin' (null = never suspended or
     *     legacy unclassified row)
     *   - suspended_by:    operator user id for administrative suspensions
     *
     * Compatibility backfill: every account suspended before this column
     * existed was suspended by a billing process (SuspendOverdueAccounts /
     * DunningService / entitlement reconciliation), and the reconciliation
     * loop previously restored all of them. Defaulting legacy rows to
     * 'billing' preserves that exact behavior.
     */
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('suspension_type', 20)->nullable()->after('suspended_at')->index();
            $table->unsignedBigInteger('suspended_by')->nullable()->after('suspension_type');
        });

        DB::table('client_accounts')
            ->whereNull('suspension_type')
            ->where(function ($query) {
                $query->where('status', 'suspended')
                    ->orWhere('service_state', ClientAccount::STATE_SUSPENDED);
            })
            ->update(['suspension_type' => ClientAccount::SUSPENSION_BILLING]);
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropIndex(['suspension_type']);
            $table->dropColumn(['suspension_type', 'suspended_by']);
        });
    }
};