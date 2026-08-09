<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * Seconds between retries.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90, 300, 900];

    protected string $phone;
    protected string $message;
    protected ?int $clientId;
    protected ?int $tenantId;

    public function __construct(string $phone, string $message, ?int $clientId = null, ?int $tenantId = null)
    {
        $this->phone    = $phone;
        $this->message  = $message;
        $this->clientId = $clientId;
        $this->tenantId = $tenantId;

        $this->onQueue('sms');
    }

    public function handle(SmsService $smsService): void
    {
        $this->establishTenantContext();

        try {
            $smsService->send($this->phone, $this->message, $this->clientId);
        } finally {
            Tenant::setCurrent(null);
        }
    }

    protected function establishTenantContext(): void
    {
        if ($this->tenantId) {
            $tenant = Tenant::find($this->tenantId);
            if ($tenant) {
                Tenant::setCurrent($tenant);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendSmsJob failed', [
            'phone' => $this->phone,
            'client_id' => $this->clientId,
            'tenant_id' => $this->tenantId,
            'message_len' => strlen($this->message),
            'exception' => $e->getMessage(),
        ]);
    }
}
