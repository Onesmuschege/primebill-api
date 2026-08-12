<?php

namespace App\Notifications;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\DunningStep;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Billing event: a dunning step (email, SMS, warning, or suspension)
 * was executed for a client's overdue invoice. Sent as the "sent"
 * confirmation copy to the client; the action side-effect (actual
 * SMS, network suspension) is performed by DunningService.
 */
class DunningSent extends Notification
{
    use Queueable;

    public function __construct(
        public Client $client,
        public Invoice $invoice,
        public DunningStep $step
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
        return (new MailMessage)
            ->subject("Dunning notice for Invoice {$this->invoice->invoice_number}")
            ->greeting("Dear {$notifiable->first_name},")
            ->line("We are following up on invoice **{$this->invoice->invoice_number}** for **KES {$this->invoice->total}**.")
            ->line("Stage: {$this->step->name}")
            ->action('Pay Now', url('/client/payments'))
            ->line('Please settle your balance to avoid service interruption.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'client_id'     => $this->client->id,
            'invoice_id'    => $this->invoice->id,
            'invoice_number'=> $this->invoice->invoice_number,
            'amount'        => (float) $this->invoice->total,
            'dunning_step'  => $this->step->name,
            'dunning_action'=> $this->step->action,
            'days_after_due'=> $this->step->days_after_due,
        ];
    }
}
