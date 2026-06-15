<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRadiusAccountingJob;
use Illuminate\Http\Request;

class RadiusAccountingController extends Controller
{
    // POST /api/webhooks/radius/accounting
    public function accounting(Request $request)
    {
        $payload = $request->all();

        ProcessRadiusAccountingJob::dispatch($payload);

        return response()->json(['success' => true]);
    }
}
