<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends the tenants table with full SaaS lifecycle management:
     * - Company details
     * - Branding configuration
     * - Subscription & licensing
     * - Quotas & limits
     * - Feature flags
     * - Billing info
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Company Details
            $table->string('contact_email')->nullable()->after('currency');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->text('address')->nullable()->after('contact_phone');
            $table->string('website')->nullable()->after('address');

            // Branding
            $table->string('logo_path')->nullable()->after('website');
            $table->string('primary_color', 7)->default('#2563eb')->after('logo_path');
            $table->string('secondary_color', 7)->default('#06b6d4')->after('primary_color');
            $table->string('custom_domain')->nullable()->unique()->after('secondary_color');

            // Subscription & Licensing
            $table->timestamp('plan_started_at')->nullable()->after('plan');
            $table->timestamp('plan_expires_at')->nullable()->after('plan_started_at');
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly')->after('plan_expires_at');
            $table->decimal('monthly_price', 10, 2)->nullable()->after('billing_cycle');

            // Quotas & Limits
            $table->unsignedInteger('storage_quota_gb')->default(10)->after('monthly_price');
            $table->unsignedInteger('api_calls_per_month')->default(10000)->after('storage_quota_gb');
            $table->unsignedInteger('max_clients')->default(500)->after('api_calls_per_month');
            $table->unsignedInteger('max_users')->default(10)->after('max_clients');
            $table->unsignedInteger('max_routers')->default(5)->after('max_users');

            // Feature Flags (JSON)
            $table->json('feature_flags')->nullable()->after('max_routers');

            // Usage Tracking
            $table->unsignedInteger('api_calls_used')->default(0)->after('feature_flags');
            $table->unsignedInteger('storage_used_mb')->default(0)->after('api_calls_used');
            $table->timestamp('last_activity_at')->nullable()->after('storage_used_mb');

            // Billing Information
            $table->string('billing_email')->nullable()->after('last_activity_at');
            $table->string('billing_contact_name')->nullable()->after('billing_email');
            $table->string('tax_name')->nullable()->after('billing_contact_name');
            $table->string('tax_number')->nullable()->after('tax_name');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('tax_number');

            // Trial & Grace Periods
            $table->timestamp('trial_ends_at')->nullable()->after('tax_rate');
            $table->timestamp('suspended_at')->nullable()->after('trial_ends_at');
            $table->timestamp('archived_at')->nullable()->after('suspended_at');
            $table->text('suspension_reason')->nullable()->after('archived_at');

            // Metadata
            $table->text('notes')->nullable()->after('suspension_reason');
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'contact_email',
                'contact_phone',
                'address',
                'website',
                'logo_path',
                'primary_color',
                'secondary_color',
                'custom_domain',
                'plan_started_at',
                'plan_expires_at',
                'billing_cycle',
                'monthly_price',
                'storage_quota_gb',
                'api_calls_per_month',
                'max_clients',
                'max_users',
                'max_routers',
                'feature_flags',
                'api_calls_used',
                'storage_used_mb',
                'last_activity_at',
                'billing_email',
                'billing_contact_name',
                'tax_name',
                'tax_number',
                'tax_rate',
                'trial_ends_at',
                'suspended_at',
                'archived_at',
                'suspension_reason',
                'notes',
                'metadata',
            ]);

        });
    }
};
