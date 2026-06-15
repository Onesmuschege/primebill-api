<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RadiusSession;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Http\Request;

class RadiusController extends Controller
{
    // POST /api/radius/sync
    public function sync(RadiusAdapterInterface $radiusAdapter)
    {
        $ok = $radiusAdapter->syncUsers();

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'RADIUS sync completed' : 'RADIUS sync failed',
        ], $ok ? 200 : 500);
    }

    // GET /api/radius/sessions
    public function sessions(Request $request)
    {
        $query = RadiusSession::with('account.client');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('username')) {
            $query->where('username', $request->username);
        }

        $sessions = $query->orderByDesc('session_start')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $sessions,
        ]);
    }
}
