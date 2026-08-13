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

// NOC — poll device metrics every 5 minutes
        $schedule->command('network:poll-metrics')->everyFiveMinutes();

        // Fiber/OLT — poll ONT optical signal every 10 minutes
        $schedule->command('fiber:poll-ont-signal')->everyTenMinutes();

        // Network Core — reconcile stale RADIUS sessions every 10 minutes
        $schedule->command('network:reconcile-sessions')->everyTenMinutes();

        // Network Core — reconcile service entitlements (suspension/restoration) every 30 minutes
        $schedule->command('network:reconcile-entitlements')->everyThirtyMinutes();

        // Network Core — evaluate FUP thresholds every hour
        $schedule->command('network:evaluate-fup')->hourly();

        // Network Core — retry failed provisioning/CoA operations every 15 minutes
        $schedule->command('network:retry-failed-provisioning')->everyFifteenMinutes();

        // Service Desk — evaluate ticket SLA targets, mark breaches and auto-escalate every 15 minutes
        $schedule->command('sla:evaluate')->everyFifteenMinutes();

                // Network Core — sync RADIUS users from billing to FreeRADIUS daily at 2 AM
        $schedule->command('radius:sync-users')->dailyAt('02:00');

                // Network Core — clean old logs after 90 days
        $schedule->command('logs:clean')->dailyAt('03:00');

        // NOC / Automation — observability maintenance
        $schedule->command('automation:flush-stale-jobs')->hourly();
        $schedule->command('automation:prune-failures')->dailyAt('03:30');
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
