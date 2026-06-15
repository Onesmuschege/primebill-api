<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:generate-invoices')->monthlyOn(1, '08:00');
Schedule::command('billing:suspend-overdue')->daily()->at('09:00');
Schedule::command('billing:send-reminders')->daily()->at('08:00');
Schedule::command('payments:reconcile-mpesa')->hourly()->withoutOverlapping();
Schedule::command('billing:reactivate-paid')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('network:poll-traffic')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('radius:sync-users')->daily()->at('02:00')->withoutOverlapping();
Schedule::command('logs:clean')->weekly();
