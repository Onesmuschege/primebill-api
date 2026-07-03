<?php

namespace App\Services\Communication;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Send a WhatsApp message via Africa's Talking Business Messaging API.
     * AT uses the same API key as SMS — no extra credentials needed.
     * Requires the client to have opted-in to WhatsApp on AT's platform.
     */
    public function send(string $phone, string $message, ?int $clientId = null): bool
    {
        $apiKey   = config('services.africastalking.api_key');
        $username = config('services.africastalking.username', 'sandbox');
        $phone    = $this->formatPhone($phone);

        try {
            $response = Http::withHeaders([
                'apiKey' => $apiKey,
                'Accept' => 'application/json',
            ])->asForm()->post('https://chat.africastalking.com/whatsapp/send', [
                'username' => $username,
                'to'       => $phone,
                'message'  => $message,
            ]);

            if ($response->successful()) {
                Log::info('WhatsAppService: message sent', ['phone' => $phone, 'client_id' => $clientId]);
                return true;
            }

            Log::warning('WhatsAppService: send failed', [
                'phone'  => $phone,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('WhatsAppService: exception', ['message' => $e->getMessage(), 'phone' => $phone]);
            return false;
        }
    }

    public function sendInvoiceReminder(\App\Models\Client $client, \App\Models\Invoice $invoice): bool
    {
        $message = "Dear {$client->first_name}, your invoice #{$invoice->invoice_number} "
            . "of KES " . number_format($invoice->total, 2) . " is due on "
            . $invoice->due_date?->format('d M Y') . ". "
            . "Pay via M-Pesa or your client portal. Thank you - PrimeBill ISP.";

        return $this->send($client->phone, $message, $client->id);
    }

    public function sendPaymentConfirmation(\App\Models\Client $client, float $amount, string $ref): bool
    {
        $message = "Payment confirmed! KES " . number_format($amount, 2)
            . " received (Ref: {$ref}). Your internet access has been restored. Thank you - PrimeBill ISP.";

        return $this->send($client->phone, $message, $client->id);
    }

    public function sendSuspensionWarning(\App\Models\Client $client, float $outstanding): bool
    {
        $message = "Dear {$client->first_name}, your account has an outstanding balance of KES "
            . number_format($outstanding, 2)
            . ". Please pay within 3 days to avoid suspension. PrimeBill ISP.";

        return $this->send($client->phone, $message, $client->id);
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '+')) $phone = substr($phone, 1);
        return '+' . $phone;
    }
}