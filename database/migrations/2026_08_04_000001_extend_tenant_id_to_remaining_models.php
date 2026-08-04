<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extends tenant_id to the remaining 19 models (the first batch —
     * users/clients/plans/invoices/payments/routers — was handled in
     * 2026_08_04_000000_create_tenants_and_add_tenant_id).
     *
     * Three tables need their unique constraints fixed alongside the plain
     * column add, because a single-tenant-wide unique becomes wrong once
     * two different ISPs could plausibly pick the same value:
     *   - settings.key      -> unique(tenant_id, key)
     *   - vouchers.code     -> unique(tenant_id, code)
     *   - idempotency_keys  -> unique(tenant_id, scope, idempotency_key)
     *
     * mpesa_transactions.checkout_request_id / mpesa_receipt_number are
     * deliberately left as global-unique — those come from Safaricom's own
     * system, so a collision there is a real problem regardless of tenant,
     * not a tenant-boundary issue.
     */
    public function up(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        if (!$tenantId) {
            throw new \RuntimeException(
                'No tenant found — run 2026_08_04_000000_create_tenants_and_add_tenant_id first.'
            );
        }

        $plainTables = [
            'client_accounts', 'tickets', 'ticket_replies', 'sms_logs',
            'expenditures', 'inventory_items', 'network_traffic',
            'radius_sessions', 'sales_commissions', 'fup_logs',
            'system_logs', 'notifications', 'ledger_entries',
            'mpesa_transactions', 'mikrotik_sync_logs', 'loyalty_points',
        ];

        foreach ($plainTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });

            DB::table($table)->update(['tenant_id' => $tenantId]);
        }

        // settings: key was globally unique — now unique per tenant.
        Schema::table('settings', function (Blueprint $blueprint) {
            $blueprint->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::table('settings')->update(['tenant_id' => $tenantId]);
        Schema::table('settings', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['key']);
            $blueprint->unique(['tenant_id', 'key']);
        });

        // vouchers: code was globally unique — now unique per tenant.
        Schema::table('vouchers', function (Blueprint $blueprint) {
            $blueprint->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::table('vouchers')->update(['tenant_id' => $tenantId]);
        Schema::table('vouchers', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['code']);
            $blueprint->unique(['tenant_id', 'code']);
        });

        // idempotency_keys: (scope, idempotency_key) was globally unique.
        Schema::table('idempotency_keys', function (Blueprint $blueprint) {
            $blueprint->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::table('idempotency_keys')->update(['tenant_id' => $tenantId]);
        Schema::table('idempotency_keys', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['scope', 'idempotency_key']);
            $blueprint->unique(['tenant_id', 'scope', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['tenant_id', 'scope', 'idempotency_key']);
            $blueprint->unique(['scope', 'idempotency_key']);
            $blueprint->dropForeign(['tenant_id']);
            $blueprint->dropColumn('tenant_id');
        });

        Schema::table('vouchers', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['tenant_id', 'code']);
            $blueprint->unique(['code']);
            $blueprint->dropForeign(['tenant_id']);
            $blueprint->dropColumn('tenant_id');
        });

        Schema::table('settings', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['tenant_id', 'key']);
            $blueprint->unique(['key']);
            $blueprint->dropForeign(['tenant_id']);
            $blueprint->dropColumn('tenant_id');
        });

        $plainTables = [
            'client_accounts', 'tickets', 'ticket_replies', 'sms_logs',
            'expenditures', 'inventory_items', 'network_traffic',
            'radius_sessions', 'sales_commissions', 'fup_logs',
            'system_logs', 'notifications', 'ledger_entries',
            'mpesa_transactions', 'mikrotik_sync_logs', 'loyalty_points',
        ];

        foreach ($plainTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};