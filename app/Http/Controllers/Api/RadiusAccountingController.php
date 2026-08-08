<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRadiusAccountingJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RadiusAccountingController extends Controller
{
    // POST /api/webhooks/radius/accounting
    public function accounting(Request $request)
    {
        // Security: verify shared-secret header. FreeRADIUS can be configured
        // to send a custom header (e.g. via exec/curl in the accounting script)
        // or we accept the standard RADIUS shared secret in X-RADIUS-SECRET.
        $expected = config('network.radius_webhook_secret', '');
        $provided = $request->header('X-RADIUS-SECRET', '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            Log::warning('RADIUS accounting webhook rejected: invalid or missing secret', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        ProcessRadiusAccountingJob::dispatch($payload);

        return response()->json(['success' => true]);
    }
}
