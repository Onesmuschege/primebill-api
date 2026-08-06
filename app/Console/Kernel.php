<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Generate subscription invoices daily at 8 AM
        $schedule->command('subscriptions:generate-invoices')->dailyAt('08:00');

        // Process expired subscriptions every hour
        $schedule->command('subscriptions:process-expired')->hourly();

        // Send subscription reminders daily at 9 AM
        $schedule->command('subscriptions:send-reminders')->dailyAt('09:00');

        // Suspend expired tenants every 6 hours
        $schedule->command('subscriptions:suspend-expired')->everySixHours();

        // Renew subscriptions after payment every 2 hours
        $schedule->command('subscriptions:renew')->everyTwoHours();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
