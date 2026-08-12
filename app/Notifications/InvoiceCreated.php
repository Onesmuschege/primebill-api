<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Billing event: a new invoice has been generated for a client.
 *
 * Dispatched by InvoiceService on successful invoice creation and by
 * the dunning engine when it first flags an invoice as overdue.
 */
class InvoiceCreated extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public bool $overdue = false
    ) {}

    public function via(object $notifiable): array
    {
        // Mail-only: the app's `notifications` table is staff-user scoped
        // (user_id), not the polymorphic notifiable schema Laravel's database
        // channel expects, so we avoid the database channel here.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->overdue ? 'overdue' : 'issued';
        $due = $this->invoice->due_date?->format('d M Y') ?? 'N/A';
        return (new MailMessage)
            ->subject("Invoice {$this->invoice->invoice_number} {$status}")
            ->greeting("Dear {$notifiable->first_name},")
            ->line("Your invoice **{$this->invoice->invoice_number}** for **KES {$this->invoice->total}** has been {$status}.")
            ->line("Due date: {$due}")
            ->action('View Invoice', url("/client/invoices/{$this->invoice->invoice_number}"))
            ->line('Thank you for doing business with us.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id'    => $this->invoice->id,
            'invoice_number'=> $this->invoice->invoice_number,
            'amount'        => (float) $this->invoice->total,
            'status'        => $this->invoice->status,
            'overdue'       => $this->overdue,
        ];
    }
}
