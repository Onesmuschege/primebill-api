<?php

namespace App\Jobs;

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

    public function __construct(string $phone, string $message, ?int $clientId = null)
    {
        $this->phone    = $phone;
        $this->message  = $message;
        $this->clientId = $clientId;

        $this->onQueue('sms');
    }

    public function handle(SmsService $smsService): void
    {
        $smsService->send($this->phone, $this->message, $this->clientId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendSmsJob failed', [
            'phone' => $this->phone,
            'client_id' => $this->clientId,
            'message_len' => strlen($this->message),
            'exception' => $e->getMessage(),
        ]);
    }
}