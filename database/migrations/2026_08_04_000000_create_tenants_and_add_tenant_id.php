<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Foundation for multi-tenant SaaS. Creates the tenants table, migrates
     * all existing data to a single "Tenant #1" record (your current live
     * ISP), and adds tenant_id to the first batch of core models.
     *
     * Deliberately NOT every model yet — see the checklist in the delivery
     * notes for which models still need this same treatment applied.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('status', ['active', 'suspended', 'trial'])->default('active');
            $table->string('plan')->default('starter'); // platform subscription tier — not used yet, reserved
            $table->string('timezone')->default('Africa/Nairobi');
            $table->string('currency', 3)->default('KES');
            $table->timestamps();
        });

        // Seed Tenant #1 from existing company settings, so the migration
        // is self-contained and doesn't depend on the seeder having run.
        $companyName = DB::table('settings')->where('key', 'company_name')->value('value') ?? 'PrimeBill ISP';

        $tenantId = DB::table('tenants')->insertGetId([
            'name'       => $companyName,
            'slug'       => \Illuminate\Support\Str::slug($companyName) ?: 'primebill',
            'status'     => 'active',
            'plan'       => 'starter',
            'timezone'   => 'Africa/Nairobi',
            'currency'   => 'KES',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First batch of core models — the pattern to replicate for the rest.
        // tenant_id stays nullable at the DB level deliberately: enforcing
        // NOT NULL after backfill requires a column ->change(), which needs
        // doctrine/dbal (not installed here). BelongsToTenant::booted()
        // auto-fills tenant_id on every create, so this is enforced at the
        // application layer instead — add doctrine/dbal later if you want
        // a hard DB-level constraint too.
        $tables = ['users', 'clients', 'plans', 'invoices', 'payments', 'routers'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });

            DB::table($table)->update(['tenant_id' => $tenantId]);
        }
    }

    public function down(): void
    {
        $tables = ['users', 'clients', 'plans', 'invoices', 'payments', 'routers'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }

        Schema::dropIfExists('tenants');
    }
};