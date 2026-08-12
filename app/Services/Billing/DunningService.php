<?php

namespace App\Services\Billing;

use App\Jobs\SuspendNetworkAccessJob;
use App\Models\Client;
use App\Models\DunningRun;
use App\Models\DunningStep;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Notifications\AccountSuspended;
use App\Notifications\DunningSent;
use App\Notifications\InvoiceOverdue;
use App\Services\Email\EmailService;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\Log;

/**
 * Dunning engine.
 *
 * Walks configurable dunning steps (email / sms / suspend / escalate) for
 * overdue invoices and executes the associated side-effects exactly once per
 * invoice+step, recording every execution in dunning_runs for idempotency and
 * auditability.
 *
 * Lifecycle (per tenant): mark unpaid past-due invoices as overdue, then for
 * each overdue invoice pick the furthest step whose days_after_due threshold
 * has been reached and execute it.
 */
class DunningService
{
    public function __construct(
        protected EmailService $emailService,
        protected SmsService $smsService
    ) {}

    /**
     * Run the dunning engine for a single tenant.
     *
     * @return array{newly_overdue:int,email:int,sms:int,suspend:int,escalate:int,skipped:int}
     */
    public function runForTenant(Tenant $tenant, int $limit = 200): array
    {
        Tenant::setCurrent($tenant);

        $summary = ['newly_overdue' => 0, 'email' => 0, 'sms' => 0, 'suspend' => 0, 'escalate' => 0, 'skipped' => 0];

        try {
            // 1. Transition unpaid past-due invoices to overdue.
            $newlyOverdue = Invoice::where('status', 'unpaid')
                ->where('due_date', '<', now())
                ->pluck('id');

            Invoice::where('status', 'unpaid')
                ->where('due_date', '<', now())
                ->update(['status' => 'overdue']);

            $summary['newly_overdue'] = $newlyOverdue->count();

            // 2. Active dunning steps for this tenant.
            $steps = DunningStep::where('is_active', true)->orderBy('sequence')->get();

            if ($steps->isEmpty()) {
                Log::warning('Dunning: no active dunning steps', ['tenant_id' => $tenant->id]);
                return $summary;
            }

            // 3. Overdue invoices for this tenant.
            $invoices = Invoice::where('status', 'overdue')
                ->with('client')
                ->orderBy('due_date')
                ->limit($limit)
                ->get();

            foreach ($invoices as $invoice) {
                $client = $invoice->client;
                if (!$client) {
                    continue;
                }

                $daysOverdue = max(0, (int) abs(now()->startOfDay()->diffInDays($invoice->due_date)));

                // Furthest step whose threshold has been reached.
                $step = $steps->filter(fn (DunningStep $s) => $daysOverdue >= $s->days_after_due)->last();

                if (!$step) {
                    continue;
                }

                // Idempotency: never re-execute the same step for the same invoice.
                // `sent` and `skipped` are final (the step was reached); `failed`
                // runs remain retryable on the next pass.
                if (DunningRun::where('invoice_id', $invoice->id)
                    ->where('dunning_step_id', $step->id)
                    ->whereIn('status', ['sent', 'skipped'])
                    ->exists()) {
                    $summary['skipped']++;
                    continue;
                }

                $firstOverdueNotice = DunningRun::where('invoice_id', $invoice->id)->doesntExist();

                try {
                    $result = $this->executeStep($step, $invoice, $client, $tenant, $firstOverdueNotice);

                    DunningRun::create([
                        'tenant_id'       => $tenant->id,
                        'client_id'       => $client->id,
                        'invoice_id'      => $invoice->id,
                        'dunning_step_id' => $step->id,
                        'status'          => $result['status'],
                        'executed_at'     => now(),
                        'notes'           => $result['notes'],
                    ]);

                    $summary[$result['bucket']]++;
                } catch (\Throwable $e) {
                    Log::error('Dunning: step execution failed', [
                        'tenant_id' => $tenant->id,
                        'invoice_id' => $invoice->id,
                        'step' => $step->name,
                        'error' => $e->getMessage(),
                    ]);

                    DunningRun::create([
                        'tenant_id'       => $tenant->id,
                        'client_id'       => $client->id,
                        'invoice_id'      => $invoice->id,
                        'dunning_step_id' => $step->id,
                        'status'          => 'failed',
                        'executed_at'     => now(),
                        'notes'           => $e->getMessage(),
                    ]);

                    $summary['skipped']++;
                }
            }

            return $summary;
        } finally {
            Tenant::setCurrent(null);
        }
    }

    /**
     * Execute a single dunning step's side-effects.
     *
     * @return array{status:string,bucket:string,notes:string}
     */
    protected function executeStep(DunningStep $step, Invoice $invoice, Client $client, Tenant $tenant, bool $firstOverdueNotice): array
    {
        switch ($step->action) {
            case 'email':
                $this->emailService->invoiceEmail($client, $invoice);
                $client->notify(new DunningSent($client, $invoice, $step));
                if ($firstOverdueNotice) {
                    $client->notify(new InvoiceOverdue($invoice));
                }
                return [
                    'status' => 'sent',
                    'bucket' => 'email',
                    'notes'  => "Email step '{$step->name}' sent.",
                ];

            case 'sms':
                $message = $this->smsService->parseTemplate(
                    $step->template ?? "Dear {name}, invoice {invoice_number} of KES {amount} is overdue ({invoice_status}). Please pay to avoid service interruption.",
                    [
                        'name'            => $client->first_name,
                        'invoice_number'  => $invoice->invoice_number,
                        'amount'          => $invoice->total,
                        'invoice_status'  => $invoice->status,
                    ]
                );
                $this->smsService->send($client->phone, $message, $client->id);
                if ($firstOverdueNotice) {
                    $client->notify(new InvoiceOverdue($invoice));
                }
                return [
                    'status' => 'sent',
                    'bucket' => 'sms',
                    'notes'  => "SMS step '{$step->name}' sent.",
                ];

            case 'suspend':
                return $this->suspendClient($step, $invoice, $client, $tenant, $firstOverdueNotice);

            case 'call':
            case 'escalate':
                // No automated integration exists yet for these actions; record
                // the run as skipped so it is not silently marked as delivered.
                return [
                    'status' => 'skipped',
                    'bucket' => 'escalate',
                    'notes'  => "Action '{$step->action}' has no automated integration yet; manual follow-up required.",
                ];

            default:
                return [
                    'status' => 'skipped',
                    'bucket' => 'skipped',
                    'notes'  => "Unknown dunning action '{$step->action}'.",
                ];
        }
    }

    /**
     * Suspend a client's active accounts and dispatch network suspension.
     *
     * @return array{status:string,bucket:string,notes:string}
     */
    protected function suspendClient(DunningStep $step, Invoice $invoice, Client $client, Tenant $tenant, bool $firstOverdueNotice): array
    {
        $accounts = $client->accounts()->where('status', 'active')->get();

        foreach ($accounts as $account) {
            $account->update(['status' => 'suspended', 'suspended_at' => now()]);
            SuspendNetworkAccessJob::dispatch($account->id, $tenant->id);
        }

        $client->update(['status' => 'suspended']);

        $client->notify(new AccountSuspended($client, 'overdue_balance'));
        if ($firstOverdueNotice) {
            $client->notify(new InvoiceOverdue($invoice));
        }

        return [
            'status' => 'sent',
            'bucket' => 'suspend',
            'notes'  => "Suspension step '{$step->name}' executed for {$accounts->count()} account(s).",
        ];
    }
    }