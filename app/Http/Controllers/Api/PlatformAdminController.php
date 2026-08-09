<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use App\Services\Platform\PlatformAdminService;
use App\Services\Platform\TenantLifecycleService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Platform-operator endpoints — this is PrimeBill's own view across every
 * ISP tenant running on it, not any single tenant's admin dashboard.
 * Every route here is gated by the 'platform_admin' middleware alias
 * (EnsurePlatformAdmin), not the 'tenant' middleware group — there is no
 * "current tenant" for these requests.
 *
 * Tenant itself has no BelongsToTenant scope (it IS the tenant list), so
 * Tenant::query() already returns every row. Client/Invoice/Payment DO have
 * the scope, so every query against them here explicitly calls
 * withoutTenantScope() — leaving that off would silently return only
 * whichever tenant happens to be bound as "current" (normally none, in
 * which case it'd already be unscoped, but never rely on that).
 */
class PlatformAdminController extends Controller
{
    use ApiResponse;

    protected PlatformAdminService $platformService;
    protected TenantLifecycleService $lifecycleService;
    protected AuditService $auditService;

    public function __construct(
        PlatformAdminService $platformService,
        TenantLifecycleService $lifecycleService,
        AuditService $auditService
    ) {
        $this->platformService = $platformService;
        $this->lifecycleService = $lifecycleService;
        $this->auditService = $auditService;
    }

    /**
     * Platform-wide snapshot: tenant counts by status, total clients and
     * revenue across every ISP on PrimeBill.
     */
    public function stats(Request $request)
    {
        $stats = $this->platformService->getStats();
        return $this->success($stats);
    }

    /**
     * Tenant list with per-tenant client count and revenue, for the
     * platform-admin tenant table.
     */
    public function tenants(Request $request)
    {
        $tenants = $this->platformService->getTenants(
            $request->status ?? null,
            $request->search ?? null
        );
        return $this->success($tenants);
    }

    public function showTenant(Tenant $tenant)
    {
        $detail = $this->platformService->getTenantDetail($tenant);

        // Add health and billing info
        $detail['health'] = $this->lifecycleService->getTenantHealth($tenant);
        $detail['billing'] = $this->lifecycleService->getTenantBilling($tenant);
        $detail['subscription_status'] = $this->lifecycleService->getSubscriptionStatus($tenant);

        return $this->success($detail);
    }

    // ─── Tenant Lifecycle Management ───────────────────────────────────────

    /**
     * Create a new tenant (ISP)
     */
    public function createTenant(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plan' => 'nullable|string|in:starter,professional,enterprise',
            'billing_cycle' => 'nullable|string|in:monthly,annual',
            'trial_days' => 'nullable|integer|min:0|max:90',
            'timezone' => 'nullable|string|max:64',
            'currency' => 'nullable|string|size:3',

            // Company Details
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:32',
            'address' => 'nullable|string',
            'website' => 'nullable|url',

            // Branding
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',

            // Admin User
            'admin_name' => 'required_with:admin_email,admin_password|string|max:255',
            'admin_email' => 'required_with:admin_name,admin_password|email',
            'admin_password' => 'required_with:admin_name,admin_email|string|min:8|confirmed',

            // Billing
            'billing_email' => 'nullable|email',
            'billing_contact_name' => 'nullable|string|max:255',
            'tax_name' => 'nullable|string|max:100',
            'tax_number' => 'nullable|string|max:100',
            'tax_rate' => 'nullable|numeric|min:0|max:100',

            // Quotas
            'max_clients' => 'nullable|integer|min:1',
            'max_users' => 'nullable|integer|min:1',
            'max_routers' => 'nullable|integer|min:1',
            'storage_quota_gb' => 'nullable|integer|min:1',
            'api_calls_per_month' => 'nullable|integer|min:1',

            // Notes
            'notes' => 'nullable|string',
        ]);

        $tenant = $this->lifecycleService->createTenant($validated, $request);

        return $this->success([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
        ], 'Tenant created successfully', 201);
    }

    /**
     * Update tenant configuration
     */
    public function updateTenant(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'contact_email' => 'sometimes|email',
            'contact_phone' => 'sometimes|string|max:32',
            'address' => 'sometimes|string',
            'website' => 'sometimes|url',
            'primary_color' => 'sometimes|string|max:7',
            'secondary_color' => 'sometimes|string|max:7',
            'custom_domain' => 'sometimes|string|max:255',
            'billing_email' => 'sometimes|email',
            'billing_contact_name' => 'sometimes|string|max:255',
            'tax_name' => 'sometimes|string|max:100',
            'tax_number' => 'sometimes|string|max:100',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'notes' => 'sometimes|string',
        ]);

        $tenant = $this->lifecycleService->updateTenant($tenant, $validated, $request);

        return $this->success($tenant, 'Tenant updated successfully');
    }

    /**
     * Configure company details
     */
    public function configureCompany(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'contact_email' => 'required|email',
            'contact_phone' => 'nullable|string|max:32',
            'address' => 'nullable|string',
            'website' => 'nullable|url',
        ]);

        $tenant = $this->lifecycleService->configureCompany($tenant, $validated, $request);

        return $this->success($tenant, 'Company details updated');
    }

    /**
     * Configure branding
     */
    public function configureBranding(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'logo_path' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'custom_domain' => 'nullable|string|max:255|unique:tenants,custom_domain,' . $tenant->id,
        ]);

        $tenant = $this->lifecycleService->configureBranding($tenant, $validated, $request);

        return $this->success($tenant, 'Branding updated');
    }

    /**
     * Configure timezone, currency, and tax settings
     */
    public function configureLocalization(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'timezone' => 'required|string|max:64',
            'currency' => 'required|string|size:3',
            'tax_name' => 'nullable|string|max:100',
            'tax_number' => 'nullable|string|max:100',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $tenant = $this->lifecycleService->configureLocalization($tenant, $validated, $request);

        return $this->success($tenant, 'Localization settings updated');
    }

    /**
     * Assign subscription plan
     */
    public function assignPlan(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'plan' => 'required|string|in:starter,professional,enterprise',
            'billing_cycle' => 'nullable|string|in:monthly,annual',
        ]);

        $tenant = $this->lifecycleService->assignPlan(
            $tenant,
            $validated['plan'],
            $validated['billing_cycle'] ?? null,
            $request
        );

        return $this->success($tenant, 'Plan assigned successfully');
    }

    /**
     * Get available plans
     */
    public function plans()
    {
        return $this->success(Tenant::PLANS);
    }

    /**
     * Suspend a tenant
     */
    public function suspend(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = $this->lifecycleService->suspend(
            $tenant,
            $validated['reason'] ?? null,
            $request
        );

        PlatformAdminService::invalidateCache();

        return $this->success(['id' => $tenant->id, 'status' => $tenant->status], 'Tenant suspended');
    }

    /**
     * Activate a tenant
     */
    public function activate(Request $request, Tenant $tenant)
    {
        $tenant = $this->lifecycleService->activate($tenant, $request);
        PlatformAdminService::invalidateCache();

        return $this->success(['id' => $tenant->id, 'status' => $tenant->status], 'Tenant activated');
    }

    /**
     * Archive a tenant
     */
    public function archive(Request $request, Tenant $tenant)
    {
        $tenant = $this->lifecycleService->archive($tenant, $request);
        PlatformAdminService::invalidateCache();

        return $this->success(['id' => $tenant->id, 'status' => $tenant->status], 'Tenant archived');
    }

    /**
     * Delete a tenant
     */
    public function destroy(Request $request, Tenant $tenant)
    {
        $request->validate([
            'confirm' => 'required|accepted',
        ]);

        $this->lifecycleService->deleteTenant($tenant, $request);
        PlatformAdminService::invalidateCache();

        return $this->success(null, 'Tenant deleted successfully');
    }

    // ─── Quotas & Limits ─────────────────────────────────────────────────

    /**
     * Update tenant quotas
     */
    public function updateQuotas(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'storage_quota_gb' => 'sometimes|integer|min:1',
            'api_calls_per_month' => 'sometimes|integer|min:1',
            'max_clients' => 'sometimes|integer|min:1',
            'max_users' => 'sometimes|integer|min:1',
            'max_routers' => 'sometimes|integer|min:1',
        ]);

        $tenant = $this->lifecycleService->updateQuotas($tenant, $validated, $request);

        return $this->success($tenant, 'Quotas updated');
    }

    // ─── Feature Flags ───────────────────────────────────────────────────

    /**
     * Update feature flags
     */
    public function updateFeatureFlags(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'feature_flags' => 'required|array',
            'feature_flags.*' => 'string',
        ]);

        $tenant = $this->lifecycleService->updateFeatureFlags($tenant, $validated['feature_flags'], $request);

        return $this->success($tenant, 'Feature flags updated');
    }

    /**
     * Add a feature flag
     */
    public function addFeatureFlag(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'feature' => 'required|string',
        ]);

        $tenant = $this->lifecycleService->addFeatureFlag($tenant, $validated['feature'], $request);

        return $this->success($tenant, 'Feature flag added');
    }

    /**
     * Remove a feature flag
     */
    public function removeFeatureFlag(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'feature' => 'required|string',
        ]);

        $tenant = $this->lifecycleService->removeFeatureFlag($tenant, $validated['feature'], $request);

        return $this->success($tenant, 'Feature flag removed');
    }

    // ─── Health & Billing ────────────────────────────────────────────────

    /**
     * Get tenant health metrics
     */
    public function tenantHealth(Tenant $tenant)
    {
        $health = $this->lifecycleService->getTenantHealth($tenant);
        return $this->success($health);
    }

    /**
     * Get tenant billing status
     */
    public function tenantBilling(Tenant $tenant)
    {
        $billing = $this->lifecycleService->getTenantBilling($tenant);
        return $this->success($billing);
    }

    /**
     * Get tenant subscription status
     */
    public function tenantSubscription(Tenant $tenant)
    {
        $status = $this->lifecycleService->getSubscriptionStatus($tenant);
        return $this->success($status);
    }

    // ─── Impersonation ───────────────────────────────────────────────────

    /**
     * Impersonate tenant admin
     */
    public function impersonate(Request $request, Tenant $tenant)
    {
        $result = $this->lifecycleService->impersonate($tenant, $request);

        return $this->success($result, 'Impersonation started');
    }

    /**
     * End impersonation
     */
    public function endImpersonation(Request $request)
    {
        $this->lifecycleService->endImpersonation($request);

        return $this->success(null, 'Impersonation ended');
    }

// ─── Audit Log ────────────────────────────────────────────────────────

    /**
     * GET /api/platform/audit-log
     *
     * Platform-wide audit trail. Reuses the existing AuditService storage
     * (SystemLog) — no duplicate audit storage is created. Supports filtering
     * by tenant_id, action, and date range, plus pagination.
     *
     * Secrets (passwords, tokens, API keys) are never persisted by
     * AuditService::maskSensitiveData, so nothing sensitive is returned here.
     */
    public function auditLog(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'action'    => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
            'per_page'  => 'nullable|integer|min:1|max:100',
        ]);

        $query = SystemLog::with('user')->orderByDesc('created_at');

        if (!empty($validated['tenant_id'])) {
            $query->where('tenant_id', $validated['tenant_id']);
        }

        if (!empty($validated['action'])) {
            $query->where('action', 'like', '%' . $validated['action'] . '%');
        }

        if (!empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to'] . ' 23:59:59');
        }

        $perPage = $validated['per_page'] ?? 20;
        $logs = $query->paginate($perPage);

        return $this->success($logs);
    }

    // ─── Create Admin User ─────────────────────────────────────────────────

    /**
     * Create admin user for tenant
     */
    public function createAdmin(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = \App\Models\User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
            'tenant_id' => $tenant->id,
        ]);

        $user->assignRole('admin');

        $this->auditService->log(
            'tenant.admin.created',
            'tenant',
            $tenant->id,
            [],
            ['email' => $user->email],
            ['description' => "Created admin user: {$user->email}"]
        );

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ], 'Admin user created', 201);
    }
}
