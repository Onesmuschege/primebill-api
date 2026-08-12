<?php

namespace Tests\Feature;

use App\Jobs\SuspendNetworkAccessJob;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\DunningRun;
use App\Models\DunningStep;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\AccountSuspended;
use App\Notifications\DunningSent;
use App\Notifications\InvoiceOverdue;
use App\Services\Billing\DunningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DunningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private DunningService $dunning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['status' => 'active']);
        $this->tenantB = Tenant::factory()->create(['status' => 'active']);

        $this->dunning = app(DunningService::class);
    }

    private function makeOverdueInvoice(Tenant $tenant, string $number, int $daysAgo): Invoice
    {
        Tenant::setCurrent($tenant);
        try {
            $client = Client::factory()->create(['tenant_id' => $tenant->id]);

            return Invoice::create([
                'client_id'      => $client->id,
                'tenant_id'      => $tenant->id,
                'invoice_number' => $number,
                'amount'         => 1500,
                'tax'            => 0,
                'total'          => 1500,
                'status'         => 'overdue',
                'due_date'       => now()->subDays($daysAgo),
            ]);
        } finally {
            Tenant::setCurrent(null);
        }
    }

    private function seedSteps(Tenant $tenant): void
    {
        Tenant::setCurrent($tenant);
        try {
            DunningStep::create(['tenant_id' => $tenant->id, 'name' => 'Email 3d',  'sequence' => 1, 'action' => 'email',   'days_after_due' => 3,  'is_active' => true]);
            DunningStep::create(['tenant_id' => $tenant->id, 'name' => 'SMS 7d',    'sequence' => 2, 'action' => 'sms',     'days_after_due' => 7,  'is_active' => true]);
            DunningStep::create(['tenant_id' => $tenant->id, 'name' => 'Suspend 21d','sequence' => 3, 'action' => 'suspend', 'days_after_due' => 21, 'is_active' => true]);
        } finally {
            Tenant::setCurrent(null);
        }
    }

    /** @test */
    public function email_step_is_executed_and_recorded(): void
    {
        Mail::fake();
        $this->seedSteps($this->tenantA);
        $invoice = $this->makeOverdueInvoice($this->tenantA, 'INV-DUN-EMAIL', 5);

        $summary = $this->dunning->runForTenant($this->tenantA);

        $this->assertSame(1, $summary['email']);
        $this->assertDatabaseHas('dunning_runs', [
            'invoice_id'      => $invoice->id,
            'dunning_step_id' => DunningStep::where('tenant_id', $this->tenantA->id)->where('action', 'email')->first()->id,
            'status'          => 'sent',
        ]);
    }

    /** @test */
    public function furthest_applicable_step_is_selected(): void
    {
        Mail::fake();
        $this->seedSteps($this->tenantA);
        $invoice = $this->makeOverdueInvoice($this->tenantA, 'INV-DUN-FURTHEST', 10);

        $this->dunning->runForTenant($this->tenantA);

        // 10 days overdue reaches the SMS step (>= 7), not the email step.
        $this->assertSame(1, DunningRun::where('invoice_id', $invoice->id)->count());
        $run = DunningRun::where('invoice_id', $invoice->id)->first();
        $this->assertSame('sms', $run->dunningStep->action);
    }

    /** @test */
    public function dunning_is_idempotent_per_invoice_and_step(): void
    {
        Mail::fake();
        $this->seedSteps($this->tenantA);
        $invoice = $this->makeOverdueInvoice($this->tenantA, 'INV-DUN-IDEMPOTENT', 25);

        $this->dunning->runForTenant($this->tenantA);
        $this->dunning->runForTenant($this->tenantA);

        // 25 days reaches the suspend step; must be recorded exactly once.
        $runs = DunningRun::where('invoice_id', $invoice->id)->get();
        $this->assertSame(1, $runs->count());
        $this->assertSame('suspend', $runs->first()->dunningStep->action);
    }
/** @test */
    public function suspension_step_suspends_accounts_and_dispatches_job(): void
    {
        Mail::fake();
        Queue::fake();
        $this->seedSteps($this->tenantA);

        Tenant::setCurrent($this->tenantA);
        try {
            $client = Client::factory()->create(['tenant_id' => $this->tenantA->id]);
            $plan = Plan::factory()->create(['tenant_id' => $this->tenantA->id, 'price' => 1500]);
            $account = ClientAccount::create([
                'tenant_id' => $this->tenantA->id,
                'client_id' => $client->id,
                'plan_id'   => $plan->id,
                'username'  => 'dun-suspend-01',
                'password'  => bcrypt('secret'),
                'type'      => 'postpaid',
                'status'    => 'active',
            ]);
            Invoice::create([
                'client_id'      => $client->id,
                'tenant_id'      => $this->tenantA->id,
                'invoice_number' => 'INV-DUN-SUSPEND',
                'amount'         => 1500,
                'tax'            => 0,
                'total'          => 1500,
                'status'         => 'overdue',
                'due_date'       => now()->subDays(25),
            ]);
        } finally {
            Tenant::setCurrent(null);
        }

        $this->dunning->runForTenant($this->tenantA);

        $account->refresh();
        $client->refresh();

        $this->assertSame('suspended', $account->status);
        $this->assertSame('suspended', $client->status);
        Queue::assertPushed(SuspendNetworkAccessJob::class, fn ($job) => $job->accountId === $account->id);
    }

    /** @test */
    public function overdue_notification_is_dispatched_on_first_notice(): void
    {
        Mail::fake();
        Notification::fake();
        $this->seedSteps($this->tenantA);

        $client = $this->makeOverdueInvoice($this->tenantA, 'INV-DUN-NOTIFY', 5)->client;

        $this->dunning->runForTenant($this->tenantA);

        Notification::assertSentTo($client, InvoiceOverdue::class);
        Notification::assertSentTo($client, DunningSent::class);
    }

    /** @test */
    public function suspension_notification_is_dispatched(): void
    {
        Mail::fake();
        Notification::fake();
        $this->seedSteps($this->tenantA);

        $client = $this->makeOverdueInvoice($this->tenantA, 'INV-DUN-SUSPNOTIFY', 25)->client;

        $this->dunning->runForTenant($this->tenantA);

        Notification::assertSentTo($client, AccountSuspended::class);
    }

    /** @test */
    public function dunning_is_isolated_per_tenant(): void
    {
        Mail::fake();
        // Both tenants have identical steps and overdue invoices; running the
        // engine for tenant A must only ever touch tenant A's records.
        $this->seedSteps($this->tenantA);
        $this->seedSteps($this->tenantB);

        $invoiceA = $this->makeOverdueInvoice($this->tenantA, 'INV-DUN-TENANT-A', 5);
        $invoiceB = $this->makeOverdueInvoice($this->tenantB, 'INV-DUN-TENANT-B', 5);

        $summary = $this->dunning->runForTenant($this->tenantA);

        $this->assertSame(1, DunningRun::where('invoice_id', $invoiceA->id)->count(), 'Tenant A invoice should be processed');
        $this->assertSame(0, DunningRun::where('invoice_id', $invoiceB->id)->count(), 'Tenant B invoice must not be touched');
        $this->assertSame(1, $summary['email']);
    }
}