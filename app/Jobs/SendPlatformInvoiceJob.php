<?php

namespace App\Jobs;

use App\Models\PlatformInvoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a PlatformInvoice to the tenant's billing contact by email.
 *
 * This app has no real mail transport wired up — the mailbox delivery path is
 * stubbed everywhere (see NotificationService, whose send* methods Log the
 * intended message). This job follows that same established convention: it
 * runs on the queue (driver 'sync' by default) and records the intended
 * delivery rather than inventing a channel the rest of the app doesn't have.
 */
class SendPlatformInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $platformInvoiceId,
        public string $toEmail
    ) {
        $this->onQueue(config('queue.default', 'default'));
    }

    public function handle(): void
    {
        $invoice = PlatformInvoice::with('tenant', 'items')->find($this->platformInvoiceId);

        if (! $invoice) {
            Log::warning('SendPlatformInvoiceJob: invoice not found', ['id' => $this->platformInvoiceId]);

            return;
        }

        $tenantName = $invoice->tenant?->name ?? "Tenant #{$invoice->tenant_id}";

        // Placeholder for real mail dispatch. The app has no configured mailer
        // today, so delivery is logged exactly like NotificationService does.
        Log::info('Platform invoice delivered', [
            'platform_invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => $invoice->total,
            'tenant_id' => $invoice->tenant_id,
            'tenant_name' => $tenantName,
            'to' => $this->toEmail,
            'subject' => "PrimeBill Invoice {$invoice->invoice_number}",
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendPlatformInvoiceJob failed', [
            'platform_invoice_id' => $this->platformInvoiceId,
            'to' => $this->toEmail,
            'exception' => $e->getMessage(),
        ]);
    }
}
