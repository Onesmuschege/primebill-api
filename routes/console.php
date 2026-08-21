<?php

use Illuminate\Support\Facades\Schedule;

// ---------------------------------------------------------------------------
// ISP billing — tenants' own invoicing of THEIR clients (unchanged, already
// working; kept here for context on ordering).
// ---------------------------------------------------------------------------
Schedule::command('billing:generate-invoices')->monthlyOn(1, '08:00');
Schedule::command('billing:suspend-overdue')->daily()->at('09:00');
Schedule::command('billing:send-reminders')->daily()->at('08:00');
Schedule::command('payments:reconcile-mpesa')->hourly()->withoutOverlapping();
Schedule::command('billing:reactivate-paid')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('billing:run-dunning')->everyThreeHours()->withoutOverlapping();

// ---------------------------------------------------------------------------
// PrimeBill's OWN subscription billing of its tenants — this block was
// entirely unscheduled until now. Order matters: reminders fire before the
// renewal window closes, invoices generate ahead of the due date, paid
// renewals process before expired ones are handled, and suspension is always
// the last step so a tenant that renewed in the same run is never wrongly
// cut off.
// ---------------------------------------------------------------------------
Schedule::command('subscriptions:send-reminders')->dailyAt('07:00');
Schedule::command('subscriptions:generate-invoices')->dailyAt('07:15');
Schedule::command('subscriptions:renew')->dailyAt('07:30');
Schedule::command('subscriptions:process-expired')->dailyAt('07:45');
Schedule::command('subscriptions:suspend-expired')->dailyAt('08:00');

// ---------------------------------------------------------------------------
// Network / NOC polling — high frequency, matches the existing
// network:poll-traffic cadence below.
// ---------------------------------------------------------------------------
Schedule::command('network:poll-traffic')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('network:poll-metrics')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fiber:poll-ont-signal')->everyTenMinutes()->withoutOverlapping();

// ---------------------------------------------------------------------------
// Network / service reconciliation — time-sensitive enough to run every
// 15 minutes (matches billing:reactivate-paid's cadence for the same reason:
// a client shouldn't stay incorrectly suspended/entitled for hours).
// ---------------------------------------------------------------------------
Schedule::command('network:reconcile-entitlements')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('network:reconcile-sessions')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('network:retry-failed-provisioning')->everyTenMinutes()->withoutOverlapping();
Schedule::command('network:evaluate-fup')->hourly();
Schedule::command('sla:evaluate')->everyFifteenMinutes();

// ---------------------------------------------------------------------------
// RADIUS sync — unchanged, already working.
// ---------------------------------------------------------------------------
Schedule::command('radius:sync-users')->daily()->at('02:00')->withoutOverlapping();

// ---------------------------------------------------------------------------
// Housekeeping — low frequency, not time-sensitive.
// ---------------------------------------------------------------------------
Schedule::command('logs:clean')->weekly();
Schedule::command('audit:cleanup')->weekly();
Schedule::command('clients:generate-referral-codes')->dailyAt('03:00');
Schedule::command('automation:flush-stale-jobs')->everyFifteenMinutes();
Schedule::command('automation:prune-failures')->dailyAt('03:30');
