<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateSubscriptionInvoices extends Command
{
    protected $signature = 'subscriptions:generate-invoices
                            {--days-ahead=7 : Generate invoices for subscriptions expiring within this many days}';

    protected $description = 'Generate invoices for upcoming subscription renewals';

    public function __construct(protected SubscriptionService $subscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $daysAhead = (int) $this->option('days-ahead');
        $cutoff = now()->addDays($daysAhead);

        $subscriptions = TenantSubscription::where('status', 'active')
            ->where('ends_at', '<=', $cutoff)
            ->where('ends_at', '>', now())
            ->with('plan', 'tenant')
            ->get();

        $count = 0;

        foreach ($subscriptions as $subscription) {
            try {
                // Prevent duplicate invoices for the same billing period
                $existingInvoice = $subscription->invoices()
                    ->where('status', '!=', 'void')
                    ->where('issue_date', '>=', now()->startOfMonth())
                    ->exists();

                if ($existingInvoice) {
                    continue;
                }

                $this->subscriptionService->generateInvoice($subscription);
                $count++;

                $this->line("Generated invoice for {$subscription->tenant->name} - {$subscription->plan->name}");
            } catch (\Exception $e) {
                Log::error('Failed to generate subscription invoice', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Generated {$count} subscription invoices.");

        return 0;
    }
}
