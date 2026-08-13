<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        // Core
        'name', 'slug', 'status', 'plan', 'timezone', 'currency',

        // Company Details
        'contact_email', 'contact_phone', 'address', 'website',

        // Branding
        'logo_path', 'primary_color', 'secondary_color', 'custom_domain',

        // Subscription & Licensing
        'plan_started_at', 'plan_expires_at', 'billing_cycle', 'monthly_price',

        // Quotas & Limits
        'storage_quota_gb', 'api_calls_per_month', 'max_clients', 'max_users', 'max_routers',

        // Feature Flags
        'feature_flags',

        // Usage Tracking
        'api_calls_used', 'storage_used_mb', 'last_activity_at',

        // Billing
        'billing_email', 'billing_contact_name', 'tax_name', 'tax_number', 'tax_rate',

        // Trial & Suspension
        'trial_ends_at', 'suspended_at', 'archived_at', 'suspension_reason',

        // Metadata
        'notes', 'metadata',
    ];

    protected $casts = [
        'plan_started_at' => 'datetime',
        'plan_expires_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'suspended_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'feature_flags' => 'array',
        'metadata' => 'array',
        'monthly_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'storage_quota_gb' => 'integer',
        'api_calls_per_month' => 'integer',
        'max_clients' => 'integer',
        'max_users' => 'integer',
        'max_routers' => 'integer',
        'api_calls_used' => 'integer',
        'storage_used_mb' => 'integer',
    ];

    /**
     * Valid plans with their pricing and limits
     */
    public const PLANS = [
        'starter' => [
            'name' => 'Starter',
            'monthly_price' => 0,
            'annual_price' => 0,
            'max_clients' => 500,
            'max_users' => 5,
            'max_routers' => 3,
            'storage_quota_gb' => 10,
            'api_calls_per_month' => 10000,
            'features' => ['basic_billing', 'basic_reports', 'email_support'],
        ],
        'professional' => [
            'name' => 'Professional',
            'monthly_price' => 99,
            'annual_price' => 990,
            'max_clients' => 2500,
            'max_users' => 15,
            'max_routers' => 10,
            'storage_quota_gb' => 50,
            'api_calls_per_month' => 50000,
            'features' => ['basic_billing', 'advanced_reports', 'api_access', 'priority_support', 'sms'],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'monthly_price' => 299,
            'annual_price' => 2990,
            'max_clients' => 10000,
            'max_users' => 50,
            'max_routers' => 50,
            'storage_quota_gb' => 200,
            'api_calls_per_month' => 200000,
            'features' => ['basic_billing', 'advanced_reports', 'api_access', 'priority_support', 'sms', 'custom_domain', 'white_label'],
        ],
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIAL = 'trial';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Get all users for this tenant
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all clients for this tenant
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Check if tenant is on a paid plan
     */
    public function isPaid(): bool
    {
        return in_array($this->plan, ['professional', 'enterprise']);
    }

    /**
     * Check if tenant is on trial
     */
    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL;
    }

    /**
     * Check if trial has expired
     */
    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Check if subscription has expired
     */
    public function isSubscriptionExpired(): bool
    {
        return $this->plan_expires_at && $this->plan_expires_at->isPast();
    }

    /**
     * Check if tenant is suspended
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Check if tenant is archived
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Check if tenant can add more clients
     */
    public function canAddClient(): bool
    {
        return $this->clients()->count() < $this->max_clients;
    }

    /**
     * Check if tenant can add more users
     */
    public function canAddUser(): bool
    {
        return $this->users()->count() < $this->max_users;
    }

    /**
     * Check if tenant can add more routers
     */
    public function canAddRouter(): bool
    {
        return $this->routers()->count() < $this->max_routers;
    }

    /**
     * Get feature flag value
     */
    public function hasFeature(string $feature): bool
    {
        $flags = $this->feature_flags ?? [];
        $planConfig = self::PLANS[$this->plan] ?? [];
        $planFeatures = $planConfig['features'] ?? [];

        return in_array($feature, $flags) || in_array($feature, $planFeatures);
    }

    /**
     * Get plan configuration
     */
    public function getPlanConfig(): array
    {
        return self::PLANS[$this->plan] ?? self::PLANS['starter'];
    }

    /**
     * Get storage usage percentage
     */
    public function getStorageUsagePercent(): float
    {
        if ($this->storage_quota_gb <= 0) return 0;
        return round(($this->storage_used_mb / ($this->storage_quota_gb * 1024)) * 100, 2);
    }

    /**
     * Get API usage percentage
     */
    public function getApiUsagePercent(): float
    {
        if ($this->api_calls_per_month <= 0) return 0;
        return round(($this->api_calls_used / $this->api_calls_per_month) * 100, 2);
    }

    /**
     * Scope for active tenants
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for trial tenants
     */
    public function scopeTrial($query)
    {
        return $query->where('status', self::STATUS_TRIAL);
    }

    /**
     * Scope for suspended tenants
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    /**
     * Scope for archived tenants
     */
    public function scopeArchived($query)
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    /**
     * Get all routers for this tenant
     */
    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }

    /**
     * Get all plans for this tenant
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Get all subscriptions for this tenant
     */
    public function subscription(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /**
     * Get all subscription invoices for this tenant
     */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /**
     * Get all invoices for this tenant
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get all payments for this tenant
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get all settings for this tenant
     */
        public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    /**
     * ── Current-tenant context (multi-tenant) ──────────────────────────────
     *
     * The active tenant is bound in the container under 'currentTenant'.
     *
     *  - HTTP requests: ResolveTenant middleware binds it from the resolver;
     *    BelongsToTenant's global scope then reads Tenant::current() so every
     *    tenant-scoped query is automatically filtered (and cross-tenant data
     *    is structurally impossible to leak).
     *  - Console/queue: a command iterates tenants and calls setCurrent()
     *    explicitly per tenant (e.g. billing:run-dunning), because there is
     *    no request to resolve from and current() is null otherwise.
     *
     * Mirrors the binding contract used by ResolveTenant::handle() so a
     * single source of truth powers both the middleware and the engine.
     */
    public static function setCurrent(?Tenant $tenant = null): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public static function current(): ?Tenant
    {
        // `bound()` guards the console/off-request path where nothing has
        // been set yet — returns null rather than throwing BindingResolution.
        return app()->bound('currentTenant') ? app('currentTenant') : null;
    }

    public static function clearCurrent(): void
    {
        app()->forgetInstance('currentTenant');
    }
}
