<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\System\OperationalHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Operational health snapshot (Core ISP Gate — Section 64).
 *
 * Exposes the operator-facing health matrix: database, queue, scheduler,
 * RADIUS, routers, provisioning and M-Pesa callback liveness — with last
 * evidence timestamps, so the NOC can answer "is the platform healthy"
 * without reading source code.
 */
class OperationalHealthController extends Controller
{
    public function __construct(protected OperationalHealthService $health)
    {
        $this->health = $health;
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->health->snapshot(),
        ]);
    }
}