<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendSmsJob;
use App\Services\Sms\SmsService;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    // POST /api/sms/send
    public function send(Request $request)
    {
        $request->validate([
            'phone'     => 'required|string',
            'message'   => 'required|string',
            'client_id' => 'nullable|exists:clients,id',
        ]);

// Queue the SMS
        SendSmsJob::dispatch(
            $request->phone,
            $request->message,
            $request->client_id,
            \App\Models\Tenant::current()?->id
        );

        return response()->json([
            'success' => true,
            'message' => 'SMS queued successfully',
        ]);
    }

    // POST /api/sms/send-bulk
    public function sendBulk(Request $request)
    {
        $request->validate([
            'phones'  => 'required|array',
            'message' => 'required|string',
        ]);

        $count = $this->smsService->sendBulk(
            $request->phones,
            $request->message
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} SMS sent successfully",
            'data'    => ['count' => $count],
        ]);
    }

    // GET /api/sms/logs
    public function logs(Request $request)
    {
        $logs = $this->smsService->getLogs($request);

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    // GET /api/sms/balance
    public function balance()
    {
        $balance = $this->smsService->getBalance();

        return response()->json([
            'success' => true,
            'data'    => ['balance' => $balance],
        ]);
    }

    // GET /api/sms/templates
    public function templates()
    {
        return response()->json([
            'success' => true,
            'data'    => config('sms.templates'),
        ]);
    }
}
