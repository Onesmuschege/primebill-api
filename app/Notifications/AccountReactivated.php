<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Billing event: a client's service has been reactivated after a
 * payment cleared their overdue balance. Driven by the dunning
 * engine / ReactivatePaidAccounts flow.
 */
class AccountReactivated extends Notification
{
    use Queueable;

    public function __construct(public Client $client) {}

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
            ->subject('Service Reactivated')
            ->greeting("Dear {$notifiable->first_name},")
            ->line('Your Internet service has been reactivated. Welcome back!')
            ->line('Your account is now in good standing.')
            ->action('View Account', url('/client/dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'client_id' => $this->client->id,
        ];
    }
}
