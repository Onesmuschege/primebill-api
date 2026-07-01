<?php

namespace App\Services\Email;

use App\Models\Client;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Send email via Laravel's mail system
     */
    public function send(string $email, string $subject, string $view, array $data = []): bool
    {
        try {
            Mail::send($view, $data, function ($message) use ($email, $subject) {
                $message->to($email)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });
            
            Log::info("Email sent to {$email}", ['subject' => $subject]);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send email to {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send invoice email
     */
    public function sendInvoice(Client $client, $invoice): bool
    {
        if (!$client->email) return false;
        
        return $this->send(
            $client->email,
            "Invoice #{$invoice->invoice_number}",
            'emails.invoice',
            ['client' => $client, 'invoice' => $invoice]
        );
    }

    /**
     * Send payment receipt
     */
    public function sendPaymentReceipt(Client $client, $payment): bool
    {
        if (!$client->email) return false;
        
        return $this->send(
            $client->email,
            "Payment Receipt - " . now()->format('d M Y'),
            'emails.payment-receipt',
            ['client' => $client, 'payment' => $payment]
        );
    }

    /**
     * Send suspension warning
     */
    public function sendSuspensionWarning(Client $client, $daysUntilSuspension): bool
    {
        if (!$client->email) return false;
        
        return $this->send(
            $client->email,
            'Account Suspension Warning',
            'emails.suspension-warning',
            ['client' => $client, 'days' => $daysUntilSuspension]
        );
    }

    /**
     * Send account suspended notification
     */
    public function sendSuspensionNotice(Client $client): bool
    {
        if (!$client->email) return false;
        
        return $this->send(
            $client->email,
            'Your Account Has Been Suspended',
            'emails.suspension-notice',
            ['client' => $client]
        );
    }
}
