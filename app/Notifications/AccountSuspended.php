<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Billing event: a client's network access has been suspended due to
 * an overdue balance. Driven by the dunning engine after the final
 * "suspend" step (or by billing:suspend-overdue when dunning is
 * not configured for a tenant).
 */
class AccountSuspended extends Notification
{
    use Queueable;

    public function __construct(
        public Client $client,
        public string $reason = 'overdue_balance'
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
            ->subject('Account Suspended')
            ->greeting("Dear {$notifiable->first_name},")
            ->line('Your Internet service has been suspended due to an overdue balance.')
            ->line('Service will be restored once payment is received and processed.')
            ->action('Make a Payment', url('/client/payments'))
            ->line('Contact support if you believe this is an error.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'client_id' => $this->client->id,
            'reason'    => $this->reason,
        ];
    }
}
