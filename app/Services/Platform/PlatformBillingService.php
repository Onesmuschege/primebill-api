<?php

namespace App\Services\Platform;

use App\Jobs\SendPlatformInvoiceJob;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceItem;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PrimeBill's own billing of its tenant ISPs — i.e. what PrimeBill bills the
 * ISP for its PRIMEBILL subscription. Deliberately separate from the
 * tenant-scoped billing (InvoiceService / SubscriptionService), which is what
 * an ISP uses to bill ITS clients.
 *
 * Pricing/tax math reuses the same contract as
 * SubscriptionService::generateInvoice: the subscription row already
 * materialised `price` at activation time, and we apply the tenant's
 * `tax_rate` identically. No pricing logic is re-invented here.
 */
class PlatformBillingService
{
    public function __construct(protected AuditService $audit) {}

    /**
     * Generate one platform invoice per active tenant subscription for the
     * given billing period (YYYY-MM). Idempotent: a tenant already invoiced
     * for the period is left untouched.
     *
     * @return array{invoices:int, ids:list<int>}
     */
    public function generateMonthlyInvoices(?string $period = null): array
    {
        $period = $period ?? now()->format('Y-m');
        $count = 0;
        $ids = [];

        TenantSubscription::where('status', 'active')
            ->with(['tenant', 'plan'])
            ->orderBy('tenant_id')
            ->each(function (TenantSubscription $sub) use ($period, &$count, &$ids) {
                if (PlatformInvoice::where('tenant_id', $sub->tenant_id)
                    ->where('billing_period', $period)->exists()) {
                    return; // already invoiced for this period
                }

                $invoice = $this->createInvoiceForSubscription($sub, $period);
                $ids[] = $invoice->id;
                $count++;
            });

        return ['invoices' => $count, 'ids' => $ids];
    }

    /**
     * Materialise a PlatformInvoice (with line items) from a live
     * TenantSubscription, reusing its stored price + the tenant's tax rate.
     */
    public function createInvoiceForSubscription(TenantSubscription $sub, ?string $period = null): PlatformInvoice
    {
        $period = $period ?? now()->format('Y-m');
        $tenant = $sub->tenant;
        $plan = $sub->plan;
        $amount = (float) $sub->price;            // materialised at activation (annualized if needed)
        $taxRate = (float) ($tenant->tax_rate ?? 0);
        $taxAmount = $amount * ($taxRate / 100);
        $total = $amount + $taxAmount;
        $invoiceNumber = $this->nextInvoiceNumber();

        return DB::transaction(function () use ($sub, $tenant, $plan, $period, $invoiceNumber, $amount, $taxRate, $taxAmount, $total) {
            $invoice = PlatformInvoice::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $sub->id,
                'invoice_number' => $invoiceNumber,
                'amount' => $amount,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'status' => 'draft',
                'billing_period' => $period,
                'issue_date' => now(),
                'due_date' => now()->addDays(14),
            ]);

            PlatformInvoiceItem::create([
                'platform_invoice_id' => $invoice->id,
                'description' => $plan->name.' ('.ucfirst($sub->billing_cycle).')',
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
            ]);

            if ($taxAmount > 0) {
                PlatformInvoiceItem::create([
                    'platform_invoice_id' => $invoice->id,
                    'description' => 'Tax ('.$taxRate.'%)',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'amount' => $taxAmount,
                ]);
            }

            $this->audit->log(
                action: 'billing.invoice.created',
                model: 'PlatformInvoice',
                modelId: $invoice->id,
                newValues: [
                    'invoice_number' => $invoiceNumber,
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $sub->id,
                    'total' => $total,
                    'billing_period' => $period,
                ]
            );

            return $invoice->fresh('items');
        });
    }

    /**
     * Push a draft/sent invoice into the "sent" state and dispatch delivery.
     * Delivery is queued (SendPlatformInvoiceJob) and — like the rest of the
     * app — logs the intended mail since no mailer is configured.
     */
    public function sendInvoice(PlatformInvoice $invoice): PlatformInvoice
    {
        if (! in_array($invoice->status, ['draft', 'sent'], true)) {
            throw new InvalidArgumentException("Invoice #{$invoice->id} cannot be sent in status '{$invoice->status}'.");
        }

        $invoice->update(['status' => 'sent']);
        $recipients = $this->billingRecipients($invoice->tenant);

        SendPlatformInvoiceJob::dispatch($invoice->id, $recipients['email']);

        $this->audit->log(
            action: 'billing.invoice.sent',
            model: 'PlatformInvoice',
            modelId: $invoice->id,
            newValues: ['recipients' => $recipients, 'status' => 'sent']
        );

        return $invoice->fresh();
    }

    /**
     * Re-deliver an already-sent invoice to the tenant's billing contact.
     * This is the platform Admin UI "resend / remind" action.
     */
    public function resendInvoice(PlatformInvoice $invoice): PlatformInvoice
    {
        if ($invoice->status === 'void') {
            throw new InvalidArgumentException('A voided invoice cannot be resent.');
        }

        $recipients = $this->billingRecipients($invoice->tenant);

        SendPlatformInvoiceJob::dispatch($invoice->id, $recipients['email']);

        if (! in_array($invoice->status, ['paid', 'void'], true)) {
            $invoice->update(['status' => 'sent']);
        }

        $this->audit->log(
            action: 'billing.invoice.resent',
            model: 'PlatformInvoice',
            modelId: $invoice->id,
            newValues: ['recipients' => $recipients, 'status' => $invoice->status]
        );

        return $invoice->fresh();
    }

    /**
     * Mark an invoice paid.
     */
    public function markPaid(PlatformInvoice $invoice, ?string $reference = null): PlatformInvoice
    {
        if ($invoice->status === 'void') {
            throw new InvalidArgumentException('A voided invoice cannot be marked paid.');
        }
        if ($invoice->status === 'paid') {
            return $invoice; // idempotent
        }

        $oldStatus = $invoice->getOriginal('status');
        $invoice->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
            'reference' => $reference,
        ])->save();

        $this->audit->log(
            action: 'billing.invoice.paid',
            model: 'PlatformInvoice',
            modelId: $invoice->id,
            oldValues: ['status' => $oldStatus],
            newValues: [
                'status' => 'paid',
                'paid_at' => now()->toDateTimeString(),
                'payment_reference' => $reference,
            ]
        );

        return $invoice->fresh();
    }

    /**
     * Mark a single invoice overdue if its due date has passed.
     */
    public function markOverdue(PlatformInvoice $invoice): PlatformInvoice
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            return $invoice;
        }
        if ($invoice->due_date !== null && $invoice->due_date->isPast()) {
            $oldStatus = $invoice->getOriginal('status');
            $invoice->update(['status' => 'overdue']);

            $this->audit->log(
                action: 'billing.invoice.overdue',
                model: 'PlatformInvoice',
                modelId: $invoice->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'overdue']
            );
        }

        return $invoice->fresh();
    }

    /**
     * Daily sweep: mark every drafted/sent invoice whose due date has passed
     * as overdue. Safe to run repeatedly.
     */
    public function reconcileOverdue(): int
    {
        $count = 0;
        PlatformInvoice::whereIn('status', ['sent', 'draft'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->each(function (PlatformInvoice $i) use (&$count) {
                $this->markOverdue($i);
                $count++;
            });

        return $count;
    }

    /**
     * Void an invoice (only draft / sent / overdue may be voided).
     */
    public function void(PlatformInvoice $invoice, ?string $reason = null): PlatformInvoice
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            throw new InvalidArgumentException("Invoice #{$invoice->id} in status '{$invoice->status}' cannot be voided.");
        }

        $oldStatus = $invoice->getOriginal('status');
        $invoice->forceFill(['status' => 'void', 'notes' => $reason])->save();

        $this->audit->log(
            action: 'billing.invoice.voided',
            model: 'PlatformInvoice',
            modelId: $invoice->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'void', 'void_reason' => $reason]
        );

        return $invoice->fresh();
    }

    /**
     * Resolve the billing contact + email for a tenant from the Tenant table
     * (billing_contact_name / billing_email), falling back to contact_email.
     */
    public function billingRecipients(?Tenant $tenant): array
    {
        if (! $tenant) {
            return ['name' => null, 'email' => null];
        }

        return [
            'name' => $tenant->billing_contact_name ?: $tenant->name,
            'email' => $tenant->billing_email ?? $tenant->contact_email,
        ];
    }

    /**
     * Monotonic, year-partitioned invoice number: PB-INV-2026-000012.
     */
    protected function nextInvoiceNumber(): string
    {
        $year = now()->year;

        return DB::transaction(function () use ($year) {
            $last = PlatformInvoice::where('invoice_number', 'like', "PB-INV-{$year}-%")->max('invoice_number');
            $seq = $last ? (int) substr($last, -6) + 1 : 1;

            return 'PB-INV-'.$year.'-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
        }, 3);
    }

    /**
     * Aggregate cross-tenant billing stats for the platform overview cards.
     */
    public function getStats(): array
    {
        return [
            'total_invoices' => PlatformInvoice::count(),
            'outstanding_total' => (float) PlatformInvoice::whereIn('status', ['draft', 'sent', 'overdue'])
                ->sum('total'),
            'paid_this_month' => (float) PlatformInvoice::where('status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total'),
            'overdue_count' => PlatformInvoice::where('status', 'overdue')->count(),
        ];
    }
}
