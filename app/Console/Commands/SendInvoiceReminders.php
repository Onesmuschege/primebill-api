<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Communication\WhatsAppService;
use App\Services\Email\EmailService;
use App\Services\Sms\SmsService;
use Illuminate\Console\Command;

class SendInvoiceReminders extends Command
{
    protected $signature   = 'billing:send-reminders';
    protected $description = 'Send SMS + WhatsApp + email reminders for invoices due in 3 days';

    public function handle(SmsService $smsService, EmailService $emailService, WhatsAppService $whatsAppService): void
    {
        $invoices = Invoice::where('status', 'unpaid')
                           ->whereBetween('due_date', [now(), now()->addDays(3)])
                           ->with('client')
                           ->get();

        $count = 0;
        foreach ($invoices as $invoice) {
            $paybill = \App\Models\Setting::where('key', 'company_paybill')->value('value');

            $smsService->send(
                $invoice->client->phone,
                "Dear {$invoice->client->first_name}, your invoice of KES {$invoice->total} is due on {$invoice->due_date->format('d/m/Y')}. Pay via M-Pesa Paybill {$paybill}.",
                $invoice->client_id
            );

            $emailService->invoiceEmail($invoice->client, $invoice);

            // Best-effort — a WhatsApp delivery failure should never block
            // the SMS/email reminders that already sent above.
            try {
                $whatsAppService->sendInvoiceReminder($invoice->client, $invoice);
            } catch (\Throwable $e) {
                $this->warn("WhatsApp reminder failed for invoice #{$invoice->id}: {$e->getMessage()}");
            }

            $count++;
        }

        $this->info("Sent {$count} reminders.");
    }
}