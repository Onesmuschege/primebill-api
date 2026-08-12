<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Billing event: an invoice has crossed its due date and is now overdue.
 * Fired once per invoice (guarded by the dunning run log) to avoid
 * duplicate reminders to the same client for the same invoice.
 */
class InvoiceOverdue extends Notification
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        // Mail-only: the app's `notifications` table is staff-user scoped
        // (user_id), not the polymorphic notifiable schema Laravel's database
        // channel expects, so we avoid the database channel here.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = $this->invoice->due_date ? abs(now()->startOfDay()->diffInDays($this->invoice->due_date, false)) : 0;
        return (new MailMessage)
            ->subject("Overdue: Invoice {$this->invoice->invoice_number}")
            ->greeting("Dear {$notifiable->first_name},")
            ->line("Invoice **{$this->invoice->invoice_number}** for **KES {$this->invoice->total}** is overdue ({$days} days past due).")
            ->line('Please settle this balance immediately to avoid service interruption.')
            ->action('Pay Now', url('/client/payments'))
            ->line('If you have already paid, please disregard this notice.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id'    => $this->invoice->id,
            'invoice_number'=> $this->invoice->invoice_number,
            'amount'        => (float) $this->invoice->total,
            'days_overdue'  => $this->invoice->due_date ? now()->startOfDay()->diffInDays($this->invoice->due_date, false) : null,
        ];
    }
}
