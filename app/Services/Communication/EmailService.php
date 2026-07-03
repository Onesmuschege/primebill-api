<?php

namespace App\Services\Communication;

use App\Models\Client;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Mail\Message;

class EmailService
{
    /**
     * Send a plain or HTML email to a client.
     * Falls back gracefully if no email configured or SMTP misconfigured.
     */
    public function sendToClient(Client $client, string $subject, string $htmlBody): bool
    {
        if (!$client->email) {
            Log::info("EmailService: client {$client->id} has no email — skipped");
            return false;
        }

        try {
            Mail::html($htmlBody, function (Message $message) use ($client, $subject) {
                $message->to($client->email, $client->full_name)
                    ->subject($subject)
                    ->from(
                        config('mail.from.address', 'noreply@primebill.co.ke'),
                        config('mail.from.name', 'PrimeBill ISP')
                    );
            });

            Log::info("EmailService: sent '{$subject}' to {$client->email}");
            return true;

        } catch (\Throwable $e) {
            Log::error("EmailService: failed sending to {$client->email}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ── Template builders ─────────────────────────────────────────────────────

    public function invoiceEmail(Client $client, \App\Models\Invoice $invoice): bool
    {
        $subject = "Invoice #{$invoice->invoice_number} — KES " . number_format($invoice->total, 2);
        $html    = $this->wrap("
            <h2>Dear {$client->first_name},</h2>
            <p>Your invoice <strong>#{$invoice->invoice_number}</strong> is ready.</p>
            <table style='width:100%;border-collapse:collapse;'>
              <tr><td>Amount Due</td><td><strong>KES " . number_format($invoice->total, 2) . "</strong></td></tr>
              <tr><td>Due Date</td><td>{$invoice->due_date?->format('d M Y')}</td></tr>
              <tr><td>Status</td><td>{$invoice->status}</td></tr>
            </table>
            <p>Pay via M-Pesa Paybill or log into your portal to pay online.</p>
        ");

        return $this->sendToClient($client, $subject, $html);
    }

    public function paymentReceiptEmail(Client $client, \App\Models\Payment $payment): bool
    {
        $subject = "Payment Receipt — KES " . number_format($payment->amount, 2);
        $html    = $this->wrap("
            <h2>Payment Confirmed</h2>
            <p>Dear {$client->first_name}, thank you for your payment.</p>
            <table style='width:100%;border-collapse:collapse;'>
              <tr><td>Amount</td><td><strong>KES " . number_format($payment->amount, 2) . "</strong></td></tr>
              <tr><td>Method</td><td>" . strtoupper($payment->method) . "</td></tr>
              <tr><td>Reference</td><td>{$payment->mpesa_code}</td></tr>
              <tr><td>Date</td><td>{$payment->created_at->format('d M Y H:i')}</td></tr>
            </table>
        ");

        return $this->sendToClient($client, $subject, $html);
    }

    public function suspensionWarningEmail(Client $client, float $outstandingAmount): bool
    {
        $subject = 'Action Required: Outstanding Balance — KES ' . number_format($outstandingAmount, 2);
        $html    = $this->wrap("
            <h2>Account Suspension Warning</h2>
            <p>Dear {$client->first_name},</p>
            <p>Your account has an outstanding balance of <strong>KES " . number_format($outstandingAmount, 2) . "</strong>.</p>
            <p>Please clear this balance within 3 days to avoid service suspension.</p>
            <p>Log into your portal or pay via M-Pesa to settle your balance.</p>
        ");

        return $this->sendToClient($client, $subject, $html);
    }

    public function accountSuspendedEmail(Client $client): bool
    {
        $subject = 'Your Account Has Been Suspended';
        $html    = $this->wrap("
            <h2>Account Suspended</h2>
            <p>Dear {$client->first_name},</p>
            <p>Your internet account has been suspended due to an unpaid balance.</p>
            <p>To restore access, please make a payment via M-Pesa or your client portal.
               Your service will resume automatically within minutes of payment.</p>
        ");

        return $this->sendToClient($client, $subject, $html);
    }

    public function accountActivatedEmail(Client $client): bool
    {
        $subject = 'Your Account is Active';
        $html    = $this->wrap("
            <h2>You're Back Online!</h2>
            <p>Dear {$client->first_name},</p>
            <p>Your internet account has been reactivated. Enjoy your connection!</p>
        ");

        return $this->sendToClient($client, $subject, $html);
    }

    // ── HTML wrapper ──────────────────────────────────────────────────────────

    private function wrap(string $content): string
    {
        $company = config('app.name', 'PrimeBill ISP');
        return "
        <!DOCTYPE html>
        <html>
        <body style='font-family:Inter,Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;'>
          <div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;'>
            <div style='background:linear-gradient(135deg,#2563eb,#06b6d4);padding:24px 32px;'>
              <h1 style='color:#fff;margin:0;font-size:20px;'>{$company}</h1>
            </div>
            <div style='padding:32px;color:#1e293b;line-height:1.6;'>
              {$content}
            </div>
            <div style='background:#f8fafc;padding:16px 32px;text-align:center;color:#94a3b8;font-size:12px;'>
              © " . date('Y') . " {$company} · Powered by DarkOpsHub
            </div>
          </div>
        </body>
        </html>";
    }
}