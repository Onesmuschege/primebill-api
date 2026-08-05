<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
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

    /**
     * Platform-wide snapshot: tenant counts by status, total clients and
     * revenue across every ISP on PrimeBill.
     */
    public function stats(Request $request)
    {
        $tenantCounts = Tenant::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalClients = Client::withoutTenantScope()->count();

        $totalRevenue = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->sum('amount');

        $outstandingInvoices = Invoice::withoutTenantScope()
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('total');

        return $this->success([
            'tenants' => [
                'total'     => Tenant::count(),
                'active'    => (int) ($tenantCounts['active'] ?? 0),
                'trial'     => (int) ($tenantCounts['trial'] ?? 0),
                'suspended' => (int) ($tenantCounts['suspended'] ?? 0),
            ],
            'total_clients'        => $totalClients,
            'total_platform_revenue' => (float) $totalRevenue,
            'outstanding_invoices' => (float) $outstandingInvoices,
        ]);
    }

    /**
     * Tenant list with per-tenant client count and revenue, for the
     * platform-admin tenant table.
     */
    public function tenants(Request $request)
    {
        $tenants = Tenant::query()
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) {
                $clientCount = Client::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->count();

                $revenue = Payment::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'completed')
                    ->sum('amount');

                return [
                    'id'            => $tenant->id,
                    'name'          => $tenant->name,
                    'slug'          => $tenant->slug,
                    'status'        => $tenant->status,
                    'plan'          => $tenant->plan,
                    'currency'      => $tenant->currency,
                    'client_count'  => $clientCount,
                    'revenue'       => (float) $revenue,
                    'created_at'    => $tenant->created_at,
                ];
            });

        return $this->success($tenants);
    }

    public function showTenant(Tenant $tenant)
    {
        $clientCount = Client::withoutTenantScope()->where('tenant_id', $tenant->id)->count();
        $revenue = Payment::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->sum('amount');
        $outstanding = Invoice::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('total');

        return $this->success([
            'id'                   => $tenant->id,
            'name'                 => $tenant->name,
            'slug'                 => $tenant->slug,
            'status'               => $tenant->status,
            'plan'                 => $tenant->plan,
            'timezone'             => $tenant->timezone,
            'currency'             => $tenant->currency,
            'client_count'         => $clientCount,
            'revenue'              => (float) $revenue,
            'outstanding_invoices' => (float) $outstanding,
            'created_at'           => $tenant->created_at,
        ]);
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => 'suspended']);

        return $this->success(['id' => $tenant->id, 'status' => $tenant->status], 'Tenant suspended');
    }

    public function activate(Tenant $tenant)
    {
        $tenant->update(['status' => 'active']);

        return $this->success(['id' => $tenant->id, 'status' => $tenant->status], 'Tenant activated');
    }
}
