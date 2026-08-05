<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the platform-admin flag used to grant cross-tenant access to
     * PrimeBill's own operators (i.e. you, running PrimeBill as a
     * business across every ISP tenant) — separate from a tenant's own
     * "super_admin" Spatie role, which only ever sees that tenant's data.
     *
     * Deliberately a plain boolean column, not a role/permission, since
     * this bypasses tenant scoping entirely (see BelongsToTenant::
     * scopeWithoutTenantScope()) and must never be grantable through the
     * ordinary roles/permissions UI a tenant admin can reach.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
