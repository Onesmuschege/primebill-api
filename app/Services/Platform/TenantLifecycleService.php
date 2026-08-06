<?php

namespace App\Services\Platform;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TenantLifecycleService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Create a new tenant with full configuration
     */
    public function createTenant(array $data, ?Request $request = null): Tenant
    {
        return DB::transaction(function () use ($data, $request) {
            // Generate unique slug
            $slug = $this->generateUniqueSlug($data['name']);

            // Determine initial status
            $status = $data['status'] ?? 'trial';
            $trialDays = $data['trial_days'] ?? 14;

            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'status' => $status,
                'plan' => $data['plan'] ?? 'starter',
                'timezone' => $data['timezone'] ?? 'Africa/Nairobi',
                'currency' => $data['currency'] ?? 'KES',

                // Company Details
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'website' => $data['website'] ?? null,

                // Branding
                'primary_color' => $data['primary_color'] ?? '#2563eb',
                'secondary_color' => $data['secondary_color'] ?? '#06b6d4',
                'custom_domain' => $data['custom_domain'] ?? null,

                // Subscription
                'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                'plan_started_at' => now(),
                'trial_ends_at' => $status === 'trial' ? now()->addDays($trialDays) : null,

                // Quotas
                'storage_quota_gb' => $data['storage_quota_gb'] ?? 10,
                'api_calls_per_month' => $data['api_calls_per_month'] ?? 10000,
                'max_clients' => $data['max_clients'] ?? 500,
                'max_users' => $data['max_users'] ?? 5,
                'max_routers' => $data['max_routers'] ?? 3,

                // Feature flags
                'feature_flags' => $data['feature_flags'] ?? [],

                // Billing
                'billing_email' => $data['billing_email'] ?? $data['admin_email'] ?? null,
                'billing_contact_name' => $data['billing_contact_name'] ?? null,
                'tax_name' => $data['tax_name'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? 0,

                // Notes
                'notes' => $data['notes'] ?? null,
            ]);

            // Seed default settings
            $this->seedDefaultSettings($tenant, $data);

            // Create initial admin user if provided
            if (!empty($data['admin_name']) && !empty($data['admin_email']) && !empty($data['admin_password'])) {
                $this->createAdminUser($tenant, $data);
            }

            // Log the creation
            $this->auditService->log(
                'tenant.created',
                'tenant',
                $tenant->id,
                [],
                ['name' => $tenant->name, 'plan' => $tenant->plan],
                ['description' => "Created tenant: {$tenant->name}"]
            );

            Log::info("Tenant created: {$tenant->name} (ID: {$tenant->id})");

            return $tenant;
        });
    }

    /**
     * Update tenant configuration
     */
    public function updateTenant(Tenant $tenant, array $data, ?Request $request = null): Tenant
    {
        $changes = [];

        foreach ($data as $key => $value) {
            if ($tenant->{$key} !== $value) {
                $changes[$key] = [
                    'from' => $tenant->{$key},
                    'to' => $value,
                ];
            }
        }

        $tenant->update($data);

        if (!empty($changes)) {
            $this->auditService->log(
                'tenant.updated',
                'tenant',
                $tenant->id,
                [],
                $changes,
                ['description' => 'Updated tenant: ' . implode(', ', array_keys($changes))]
            );
        }

        return $tenant;
    }

    /**
     * Configure company details
     */
    public function configureCompany(Tenant $tenant, array $data, ?Request $request = null): Tenant
    {
        $tenant->update([
            'contact_email' => $data['contact_email'] ?? $tenant->contact_email,
            'contact_phone' => $data['contact_phone'] ?? $tenant->contact_phone,
            'address' => $data['address'] ?? $tenant->address,
            'website' => $data['website'] ?? $tenant->website,
        ]);

        $this->auditService->log(
            'tenant.company.updated',
            'tenant',
            $tenant->id,
            [],
            $data,
            ['description' => 'Updated company details']
        );

        return $tenant;
    }

    /**
     * Configure branding
     */
    public function configureBranding(Tenant $tenant, array $data, ?Request $request = null): Tenant
    {
        $tenant->update([
            'logo_path' => $data['logo_path'] ?? $tenant->logo_path,
            'primary_color' => $data['primary_color'] ?? $tenant->primary_color,
            'secondary_color' => $data['secondary_color'] ?? $tenant->secondary_color,
            'custom_domain' => $data['custom_domain'] ?? $tenant->custom_domain,
        ]);

        // Update portal settings in tenant's settings table
        if (isset($data['primary_color']) || isset($data['secondary_color'])) {
            Setting::updateOrCreate(
                ['key' => 'portal_primary_color', 'tenant_id' => $tenant->id],
                ['value' => $data['primary_color'] ?? '#2563eb', 'group' => 'portal']
            );
            Setting::updateOrCreate(
                ['key' => 'portal_secondary_color', 'tenant_id' => $tenant->id],
                ['value' => $data['secondary_color'] ?? '#06b6d4', 'group' => 'portal']
            );
        }

        $this->auditService->log(
            'tenant.branding.updated',
            'tenant',
            $tenant->id,
            [],
            $data,
            ['description' => 'Updated branding configuration']
        );

        return $tenant;
    }

    /**
     * Configure timezone, currency, and tax settings
     */
    public function configureLocalization(Tenant $tenant, array $data, ?Request $request = null): Tenant
    {
        $tenant->update([
            'timezone' => $data['timezone'] ?? $tenant->timezone,
            'currency' => $data['currency'] ?? $tenant->currency,
            'tax_name' => $data['tax_name'] ?? $tenant->tax_name,
            'tax_number' => $data['tax_number'] ?? $tenant->tax_number,
            'tax_rate' => $data['tax_rate'] ?? $tenant->tax_rate,
        ]);

        // Update tenant settings
        Setting::updateOrCreate(
            ['key' => 'timezone', 'tenant_id' => $tenant->id],
            ['value' => $data['timezone'] ?? 'Africa/Nairobi', 'group' => 'system']
        );
        Setting::updateOrCreate(
            ['key' => 'currency', 'tenant_id' => $tenant->id],
            ['value' => $data['currency'] ?? 'KES', 'group' => 'billing']
        );
        Setting::updateOrCreate(
            ['key' => 'tax_rate', 'tenant_id' => $tenant->id],
            ['value' => (string) ($data['tax_rate'] ?? 0), 'group' => 'billing']
        );

        $this->auditService->log(
            'tenant.localization.updated',
            'tenant',
            $tenant->id,
            [],
            $data,
            ['description' => 'Updated localization settings']
        );

        return $tenant;
    }

    /**
     * Assign subscription plan
     */
    public function assignPlan(Tenant $tenant, string $plan, ?string $billingCycle = null, ?Request $request = null): Tenant
    {
        $planConfig = Tenant::PLANS[$plan] ?? null;

        if (!$planConfig) {
            throw new \InvalidArgumentException("Invalid plan: {$plan}");
        }

        $billingCycle = $billingCycle ?? $tenant->billing_cycle;
        $expiresAt = $billingCycle === 'annual'
            ? now()->addYear()
            : now()->addMonth();

        $tenant->update([
            'plan' => $plan,
            'plan_started_at' => now(),
            'plan_expires_at' => $expiresAt,
            'billing_cycle' => $billingCycle,
            'monthly_price' => $billingCycle === 'annual'
                ? $planConfig['annual_price'] / 12
                : $planConfig['monthly_price'],
            'max_clients' => $planConfig['max_clients'],
            'max_users' => $planConfig['max_users'],
            'max_routers' => $planConfig['max_routers'],
            'storage_quota_gb' => $planConfig['storage_quota_gb'],
            'api_calls_per_month' => $planConfig['api_calls_per_month'],
            'status' => $tenant->status === 'trial' ? 'active' : $tenant->status,
            'trial_ends_at' => null, // Clear trial when assigned a plan
        ]);

        $this->auditService->log(
            'tenant.plan.assigned',
            'tenant',
            $tenant->id,
            [],
            ['plan' => $plan, 'billing_cycle' => $billingCycle],
            ['description' => "Assigned plan: {$plan} ({$billingCycle})"]
        );

        return $tenant;
    }

    /**
     * Activate a tenant
     */
    public function activate(Tenant $tenant, ?Request $request = null): Tenant
    {
        $tenant->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $this->auditService->log(
            'tenant.activated',
            'tenant',
            $tenant->id,
            [],
            ['status' => 'active'],
            ['description' => 'Tenant activated']
        );

        return $tenant;
    }

    /**
     * Suspend a tenant
     */
    public function suspend(Tenant $tenant, ?string $reason = null, ?Request $request = null): Tenant
    {
        $tenant->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => $reason ?? 'Suspended by administrator',
        ]);

        $this->auditService->log(
            'tenant.suspended',
            'tenant',
            $tenant->id,
            [],
            ['reason' => $reason],
            ['description' => 'Tenant suspended: ' . ($reason ?? 'No reason provided')]
        );

        return $tenant;
    }

    /**
     * Archive a tenant
     */
    public function archive(Tenant $tenant, ?Request $request = null): Tenant
    {
        $tenant->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $this->auditService->log(
            'tenant.archived',
            'tenant',
            $tenant->id,
            [],
            ['status' => 'archived'],
            ['description' => 'Tenant archived']
        );

        return $tenant;
    }

    /**
     * Delete a tenant (with all data)
     */
    public function deleteTenant(Tenant $tenant, ?Request $request = null): bool
    {
        $tenantName = $tenant->name;
        $tenantId = $tenant->id;

        // Log before deletion
        $this->auditService->log(
            'tenant.deleted',
            'tenant',
            $tenantId,
            [],
            ['name' => $tenantName],
            ['description' => "Deleted tenant: {$tenantName}"]
        );

        // Delete all tenant data
        $this->deleteTenantData($tenant);

        // Delete the tenant
        $tenant->delete();

        Log::warning("Tenant deleted: {$tenantName} (ID: {$tenantId})");

        return true;
    }

    /**
     * Delete all data associated with a tenant
     */
    protected function deleteTenantData(Tenant $tenant): void
    {
        // Delete in order of dependencies
        $tenant->clients()->each(function ($client) {
            $client->accounts()->delete();
            $client->invoices()->delete();
            $client->payments()->delete();
            $client->tickets()->delete();
        });

        $tenant->clients()->delete();
        $tenant->users()->delete();
        $tenant->routers()->delete();
        $tenant->plans()->delete();
        $tenant->settings()->delete();
    }

    /**
     * Update quotas
     */
    public function updateQuotas(Tenant $tenant, array $quotas, ?Request $request = null): Tenant
    {
        $tenant->update([
            'storage_quota_gb' => $quotas['storage_quota_gb'] ?? $tenant->storage_quota_gb,
            'api_calls_per_month' => $quotas['api_calls_per_month'] ?? $tenant->api_calls_per_month,
            'max_clients' => $quotas['max_clients'] ?? $tenant->max_clients,
            'max_users' => $quotas['max_users'] ?? $tenant->max_users,
            'max_routers' => $quotas['max_routers'] ?? $tenant->max_routers,
        ]);

        $this->auditService->log(
            'tenant.quotas.updated',
            'tenant',
            $tenant->id,
            [],
            $quotas,
            ['description' => 'Updated tenant quotas']
        );

        return $tenant;
    }

    /**
     * Manage feature flags
     */
    public function updateFeatureFlags(Tenant $tenant, array $flags, ?Request $request = null): Tenant
    {
        $tenant->update([
            'feature_flags' => $flags,
        ]);

        $this->auditService->log(
            'tenant.features.updated',
            'tenant',
            $tenant->id,
            [],
            ['feature_flags' => $flags],
            ['description' => 'Updated feature flags: ' . implode(', ', $flags)]
        );

        return $tenant;
    }

    /**
     * Add a single feature flag
     */
    public function addFeatureFlag(Tenant $tenant, string $flag, ?Request $request = null): Tenant
    {
        $flags = $tenant->feature_flags ?? [];

        if (!in_array($flag, $flags)) {
            $flags[] = $flag;
            $tenant->update(['feature_flags' => $flags]);

            $this->auditService->log(
                'tenant.feature.added',
                'tenant',
                $tenant->id,
                [],
                ['feature' => $flag],
                ['description' => "Added feature flag: {$flag}"]
            );
        }

        return $tenant;
    }

    /**
     * Remove a single feature flag
     */
    public function removeFeatureFlag(Tenant $tenant, string $flag, ?Request $request = null): Tenant
    {
        $flags = $tenant->feature_flags ?? [];
        $flags = array_diff($flags, [$flag]);

        $tenant->update(['feature_flags' => array_values($flags)]);

        $this->auditService->log(
            'tenant.feature.removed',
            'tenant',
            $tenant->id,
            [],
            ['feature' => $flag],
            ['description' => "Removed feature flag: {$flag}"]
        );

        return $tenant;
    }

    /**
     * Get tenant health metrics
     */
    public function getTenantHealth(Tenant $tenant): array
    {
        $clientCount = $tenant->clients()->count();
        $userCount = $tenant->users()->count();
        $routerCount = $tenant->routers()->count();

        $activeClients = $tenant->clients()->where('status', 'active')->count();
        $suspendedClients = $tenant->clients()->where('status', 'suspended')->count();

        $totalRevenue = $tenant->payments()->where('status', 'completed')->sum('amount');
        $outstandingInvoices = $tenant->invoices()->whereIn('status', ['pending', 'overdue'])->sum('total');

        $onlineRouters = $tenant->routers()->where('status', 'online')->count();

        return [
            'client_count' => $clientCount,
            'client_limit' => $tenant->max_clients,
            'client_usage_percent' => $tenant->max_clients > 0
                ? round(($clientCount / $tenant->max_clients) * 100, 2)
                : 0,
            'active_clients' => $activeClients,
            'suspended_clients' => $suspendedClients,
            'user_count' => $userCount,
            'user_limit' => $tenant->max_users,
            'user_usage_percent' => $tenant->max_users > 0
                ? round(($userCount / $tenant->max_users) * 100, 2)
                : 0,
            'router_count' => $routerCount,
            'router_limit' => $tenant->max_routers,
            'router_usage_percent' => $tenant->max_routers > 0
                ? round(($routerCount / $tenant->max_routers) * 100, 2)
                : 0,
            'online_routers' => $onlineRouters,
            'offline_routers' => $routerCount - $onlineRouters,
            'total_revenue' => (float) $totalRevenue,
            'outstanding_invoices' => (float) $outstandingInvoices,
            'storage_usage_mb' => $tenant->storage_used_mb,
            'storage_limit_mb' => $tenant->storage_quota_gb * 1024,
            'storage_usage_percent' => $tenant->getStorageUsagePercent(),
            'api_usage' => $tenant->api_calls_used,
            'api_limit' => $tenant->api_calls_per_month,
            'api_usage_percent' => $tenant->getApiUsagePercent(),
            'last_activity' => $tenant->last_activity_at?->toISOString(),
        ];
    }

    /**
     * Get tenant billing status
     */
    public function getTenantBilling(Tenant $tenant): array
    {
        $totalPaid = $tenant->payments()->where('status', 'completed')->sum('amount');
        $pendingInvoices = $tenant->invoices()->where('status', 'pending')->count();
        $overdueInvoices = $tenant->invoices()->where('status', 'overdue')->count();

        $planConfig = $tenant->getPlanConfig();

        return [
            'plan' => $tenant->plan,
            'plan_name' => $planConfig['name'] ?? 'Unknown',
            'billing_cycle' => $tenant->billing_cycle,
            'monthly_price' => (float) ($tenant->monthly_price ?? 0),
            'plan_started_at' => $tenant->plan_started_at?->toISOString(),
            'plan_expires_at' => $tenant->plan_expires_at?->toISOString(),
            'days_until_expiry' => $tenant->plan_expires_at
                ? now()->diffInDays($tenant->plan_expires_at, false)
                : null,
            'is_trial' => $tenant->isOnTrial(),
            'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
            'days_until_trial_end' => $tenant->trial_ends_at
                ? now()->diffInDays($tenant->trial_ends_at, false)
                : null,
            'total_paid' => (float) $totalPaid,
            'pending_invoices' => $pendingInvoices,
            'overdue_invoices' => $overdueInvoices,
            'billing_email' => $tenant->billing_email,
            'billing_contact_name' => $tenant->billing_contact_name,
            'tax_name' => $tenant->tax_name,
            'tax_number' => $tenant->tax_number,
            'tax_rate' => (float) $tenant->tax_rate,
        ];
    }

    /**
     * Impersonate tenant admin securely
     */
    public function impersonate(Tenant $tenant, ?Request $request = null): array
    {
        // Find the admin user for this tenant
        $admin = $tenant->users()->role('admin')->first();

        if (!$admin) {
            throw new \RuntimeException("No admin user found for tenant: {$tenant->name}");
        }

        // Generate impersonation token
        $impersonationToken = Str::random(64);

        // Store impersonation session
        $request?->session()->put('impersonating_tenant', $tenant->id);
        $request?->session()->put('original_user_id', $request?->user()?->id);
        $request?->session()->put('impersonation_token', $impersonationToken);

        // Log impersonation
        $this->auditService->log(
            'tenant.impersonated',
            'tenant',
            $tenant->id,
            [],
            ['admin_email' => $admin->email],
            ['description' => "Impersonated tenant admin: {$admin->email}"]
        );

        // Create token for the admin
        $token = $admin->createToken('impersonation', ['admin'])->plainTextToken;

        return [
            'token' => $token,
            'impersonation_token' => $impersonationToken,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
        ];
    }

    /**
     * End impersonation session
     */
    public function endImpersonation(?Request $request = null): void
    {
        $tenantId = $request?->session()->get('impersonating_tenant');

        if ($tenantId) {
            $this->auditService->log(
                'tenant.impersonation_ended',
                'tenant',
                $tenantId,
                [],
                [],
                ['description' => 'Ended impersonation session']
            );
        }

        $request?->session()->forget('impersonating_tenant');
        $request?->session()->forget('original_user_id');
        $request?->session()->forget('impersonation_token');
    }

    /**
     * Check if user is currently impersonating
     */
    public function isImpersonating(?Request $request = null): bool
    {
        return $request?->session()->has('impersonating_tenant') ?? false;
    }

    /**
     * Get tenant subscription status
     */
    public function getSubscriptionStatus(Tenant $tenant): array
    {
        $isExpired = $tenant->isSubscriptionExpired();
        $isTrialExpired = $tenant->isTrialExpired();

        $status = 'active';
        $message = null;

        if ($tenant->isSuspended()) {
            $status = 'suspended';
            $message = $tenant->suspension_reason;
        } elseif ($tenant->isArchived()) {
            $status = 'archived';
            $message = 'Tenant has been archived';
        } elseif ($tenant->isOnTrial() && $isTrialExpired) {
            $status = 'trial_expired';
            $message = 'Trial period has expired';
        } elseif ($isExpired) {
            $status = 'expired';
            $message = 'Subscription has expired';
        } elseif ($tenant->isOnTrial()) {
            $status = 'trial';
            $daysLeft = now()->diffInDays($tenant->trial_ends_at, false);
            $message = "{$daysLeft} days remaining in trial";
        }

        return [
            'status' => $status,
            'message' => $message,
            'is_active' => $status === 'active',
            'is_suspended' => $tenant->isSuspended(),
            'is_archived' => $tenant->isArchived(),
            'is_trial' => $tenant->isOnTrial(),
            'is_expired' => $isExpired,
            'is_trial_expired' => $isTrialExpired,
        ];
    }

    /**
     * Generate unique slug
     */
    protected function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'isp';
        $slug = $base;
        $i = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$i;
        }

        return $slug;
    }

    /**
     * Seed default settings for new tenant
     */
    protected function seedDefaultSettings(Tenant $tenant, array $data): void
    {
        $defaults = [
            ['key' => 'company_name', 'value' => $tenant->name, 'group' => 'company'],
            ['key' => 'invoice_prefix', 'value' => 'INV', 'group' => 'billing'],
            ['key' => 'tax_rate', 'value' => '0', 'group' => 'billing'],
            ['key' => 'grace_period', 'value' => '3', 'group' => 'billing'],
            ['key' => 'currency', 'value' => $tenant->currency, 'group' => 'billing'],
            ['key' => 'timezone', 'value' => $tenant->timezone, 'group' => 'system'],
            ['key' => 'date_format', 'value' => 'd/m/Y', 'group' => 'system'],
            ['key' => 'portal_business_name', 'value' => $tenant->name, 'group' => 'portal'],
            ['key' => 'portal_welcome_message', 'value' => 'Select a plan and pay via M-Pesa', 'group' => 'portal'],
            ['key' => 'portal_primary_color', 'value' => $tenant->primary_color, 'group' => 'portal'],
            ['key' => 'portal_secondary_color', 'value' => $tenant->secondary_color, 'group' => 'portal'],
            ['key' => 'portal_support_phone', 'value' => '', 'group' => 'portal'],
            ['key' => 'portal_terms_text', 'value' => '', 'group' => 'portal'],
        ];

        foreach ($defaults as $setting) {
            $model = new Setting($setting);
            $model->tenant_id = $tenant->id;
            $model->save();
        }
    }

    /**
     * Create initial admin user
     */
    protected function createAdminUser(Tenant $tenant, array $data): User
    {
        $user = User::create([
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => Hash::make($data['admin_password']),
        ]);

        $user->tenant_id = $tenant->id;
        $user->save();

        $user->assignRole('admin');

        return $user;
    }

    /**
     * Update last activity timestamp
     */
    public function updateLastActivity(Tenant $tenant): void
    {
        $tenant->update(['last_activity_at' => now()]);
    }

    /**
     * Track API usage
     */
    public function trackApiUsage(Tenant $tenant, int $calls = 1): void
    {
        $tenant->increment('api_calls_used', $calls);
    }

    /**
     * Reset monthly API usage
     */
    public function resetApiUsage(Tenant $tenant): void
    {
        $tenant->update(['api_calls_used' => 0]);
    }
}
