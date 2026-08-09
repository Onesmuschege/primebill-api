<?php

namespace Database\Seeders;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the tenant<->subscription-plans link for each demo tenant, plus a
 * SubscriptionInvoice per tenant so the platform subscription billing UI has
 * realistic data.
 *
 * TenantSubscription is tenant-owned (has tenant_id); SubscriptionPlan is
 * global (no tenant_id). The SubscriptionInvoice is tenant-owned.
 */
class TenantSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $plan = SubscriptionPlan::where('slug', $tenant->plan)->first();

            if (! $plan) {
                $this->command->warn("No subscription plan matching tenant '{$tenant->slug}' plan '{$tenant->plan}'. Skipping subscription.");
                continue;
            }

            $status = match ($tenant->status) {
                'trial'    => 'trial',
                'suspended'=> 'suspended',
                'archived' => 'cancelled',
                default    => 'active',
            };

            $startsAt  = $tenant->plan_started_at ?? now()->subMonths(2);
            $endsAt    = $tenant->plan_expires_at ?? $startsAt->copy()->addMonth();

            $subscription = TenantSubscription::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'plan_id'   => $plan->id,
                ],
                [
                    'name'            => $plan->name . ' Subscription',
                    'status'          => $status,
                    'billing_cycle'   => $tenant->billing_cycle ?? 'monthly',
                    'price'           => $plan->price,
                    'annual_price'    => $plan->annual_price,
                    'trial_ends_at'   => $tenant->trial_ends_at,
                    'starts_at'       => $startsAt,
                    'ends_at'         => $endsAt,
                    'grace_days'      => $plan->grace_days,
                    'metadata'        => ['source' => 'seeder', 'plan_slug' => $plan->slug],
                ]
            );

            // Subscription invoice for the current billing period.
            $invoiceNumber = 'SUB-' . date('Y') . '-' . str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT);

            SubscriptionInvoice::updateOrCreate(
                ['invoice_number' => $invoiceNumber],
                [
                    'tenant_id'      => $tenant->id,
                    'subscription_id'=> $subscription->id,
                    'amount'         => $plan->price,
                    'tax_amount'     => round($plan->price * 0.16, 2),
                    'total'          => round($plan->price * 1.16, 2),
                    'status'         => $status === 'active' ? 'paid' : 'pending',
                    'issue_date'     => $startsAt,
                    'due_date'       => $startsAt->copy()->addDays($plan->grace_days),
                    'paid_at'        => $status === 'active' ? $startsAt->copy()->addDay() : null,
                    'line_items'     => [
                        ['description' => $plan->name . ' subscription', 'amount' => $plan->price],
                    ],
                ]
            );
        }

        $this->command->info('TenantSubscriptionSeeder: tenant subscriptions + invoices seeded.');
    }
}
