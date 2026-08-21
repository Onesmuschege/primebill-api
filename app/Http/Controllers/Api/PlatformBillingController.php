<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\Tenant;
use App\Services\Platform\PlatformBillingService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Throwable;

class PlatformBillingController extends Controller
{
    use ApiResponse;

    public function __construct(protected PlatformBillingService $billing) {}

    /**
     * GET /api/platform/billing/invoices
     * Paginated list, scoped to a tenant (`?tenant_id=`) and/or status.
     */
    public function index(Request $request)
    {
        $request->validate([
            'tenant_id' => 'nullable|exists:tenants,id',
            'status' => 'nullable|in:draft,sent,paid,overdue,void,all',
            'billing_period' => 'nullable|string|max:7',
        ]);

        $query = PlatformInvoice::with(['tenant', 'subscription.plan']);

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->integer('tenant_id'));
        }
        if ($request->filled('status') && $request->string('status') !== 'all') {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('billing_period')) {
            $query->where('billing_period', $request->string('billing_period'));
        }

        $invoices = $query->orderByDesc('created_at')->paginate(15);

        return $this->success($invoices);
    }

    /**
     * POST /api/platform/billing/invoices/generate
     * On-demand run of the monthly invoice generation (platform admin only).
     * Accepts an optional billing period (YYYY-MM); defaults to the current
     * month. Idempotent per tenant + period.
     */
    public function generate(Request $request)
    {
        $period = $request->query('period');
        $result = $this->billing->generateMonthlyInvoices($period);

        return $this->success($result, "Generated {$result['invoices']} platform invoice(s)");
    }

    /**
     * GET /api/platform/billing/stats
     * Cross-tenant aggregate counts for the platform billing overview cards.
     */
    public function stats()
    {
        return $this->success($this->billing->getStats());
    }

    /**
     * GET /api/platform/billing/invoices/{invoice}
     */
    public function show(PlatformInvoice $invoice)
    {
        $invoice->load(['tenant', 'subscription.plan', 'items']);

        $recipients = $this->billing->billingRecipients($invoice->tenant);

        return $this->success([
            'invoice' => $invoice,
            'recipients' => $recipients,
        ]);
    }

    /**
     * POST /api/platform/billing/invoices/{invoice}/mark-paid
     */
    public function markPaid(Request $request, PlatformInvoice $invoice)
    {
        $request->validate(['reference' => 'nullable|string|max:100']);

        try {
            $invoice = $this->billing->markPaid($invoice, $request->input('reference'));
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($invoice, 'Invoice marked paid');
    }

    /**
     * POST /api/platform/billing/invoices/{invoice}/void
     */
    public function void(Request $request, PlatformInvoice $invoice)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $invoice = $this->billing->void($invoice, $request->input('reason'));
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($invoice, 'Invoice voided');
    }

    /**
     * POST /api/platform/billing/invoices/{invoice}/resend
     * Re-delivers the invoice to the tenant's billing contact (queued).
     */
    public function resend(PlatformInvoice $invoice)
    {
        try {
            $invoice = $this->billing->resendInvoice($invoice);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($invoice, 'Invoice delivery queued');
    }

    /**
     * GET /api/platform/billing/invoices/{invoice}/pdf
     * Stream the invoice PDF to the browser (dompdf).
     */
    public function pdf(PlatformInvoice $invoice)
    {
        $invoice->load(['tenant', 'subscription.plan', 'items']);
        $recipients = $this->billing->billingRecipients($invoice->tenant);

        $html = view('pdf.platform-invoice', [
            'invoice' => $invoice,
            'tenant' => $invoice->tenant,
            'plan' => $invoice->subscription?->plan,
            'items' => $invoice->items,
            'recipients' => $recipients,
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setWarnings(false)
            ->download($invoice->invoice_number.'.pdf');
    }

    /**
     * POST /api/platform/billing/invoices/{invoice}/send
     * Explicitly move a draft to sent + queue delivery.
     */
    public function send(PlatformInvoice $invoice)
    {
        try {
            $invoice = $this->billing->sendInvoice($invoice);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($invoice, 'Invoice sent; delivery queued');
    }
}
