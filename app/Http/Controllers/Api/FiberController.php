<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\DistributionPoint;
use App\Models\FiberRoute;
use App\Models\FiberSplitter;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 — Fiber infrastructure controller.
 *
 * CRUD for the passive fiber plant: routes, splitters, cabinets, and
 * distribution points.
 */
class FiberController extends Controller
{
    use ApiResponse;

    // ── Fiber Routes ───────────────────────────────────────────────────────

    public function routesIndex(Request $request)
    {
        $routes = FiberRoute::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->paginate($request->input('per_page', 25));

        return $this->success($routes);
    }

    public function routesStore(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'source'      => 'sometimes|nullable|string|max:191',
            'destination' => 'sometimes|nullable|string|max:191',
            'length_km'   => 'sometimes|nullable|numeric|min:0',
            'cable_type'  => 'sometimes|nullable|string|max:191',
            'status'      => 'sometimes|in:active,planned,maintenance',
            'notes'       => 'sometimes|nullable|string',
        ]);

        $route = FiberRoute::create($data);

        return $this->success($route, 'Fiber route created', 201);
    }

    public function routesUpdate(Request $request, FiberRoute $fiberRoute)
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:191',
            'source'      => 'sometimes|nullable|string|max:191',
            'destination' => 'sometimes|nullable|string|max:191',
            'length_km'   => 'sometimes|nullable|numeric|min:0',
            'cable_type'  => 'sometimes|nullable|string|max:191',
            'status'      => 'sometimes|in:active,planned,maintenance',
            'notes'       => 'sometimes|nullable|string',
        ]);

        $fiberRoute->update($data);

        return $this->success($fiberRoute, 'Fiber route updated');
    }

    public function routesDestroy(FiberRoute $fiberRoute)
    {
        $fiberRoute->delete();

        return $this->success(null, 'Fiber route deleted');
    }

    // ── Fiber Splitters ────────────────────────────────────────────────────

    public function splittersIndex(Request $request)
    {
        $splitters = FiberSplitter::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')
            ->paginate($request->input('per_page', 25));

        return $this->success($splitters);
    }

    public function splittersStore(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:191',
            'split_ratio'=> 'sometimes|in:1:4,1:8,1:16,1:32,1:64',
            'location'   => 'sometimes|nullable|string|max:191',
            'location_lat' => 'sometimes|nullable|numeric',
            'location_lng' => 'sometimes|nullable|numeric',
            'status'     => 'sometimes|in:active,inactive',
        ]);

        $splitter = FiberSplitter::create($data);

        return $this->success($splitter, 'Splitter created', 201);
    }

    public function splittersDestroy(FiberSplitter $fiberSplitter)
    {
        $fiberSplitter->delete();

        return $this->success(null, 'Splitter deleted');
    }

    // ── Cabinets ───────────────────────────────────────────────────────────

    public function cabinetsIndex(Request $request)
    {
        $cabinets = Cabinet::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderBy('name')
            ->paginate($request->input('per_page', 25));

        return $this->success($cabinets);
    }

    public function cabinetsStore(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:191',
            'type'       => 'sometimes|in:fiber,power,distribution',
            'location'   => 'sometimes|nullable|string|max:191',
            'location_lat' => 'sometimes|nullable|numeric',
            'location_lng' => 'sometimes|nullable|numeric',
            'status'     => 'sometimes|in:active,inactive',
            'capacity'   => 'sometimes|nullable|string|max:191',
            'notes'      => 'sometimes|nullable|string',
        ]);

        $cabinet = Cabinet::create($data);

        return $this->success($cabinet, 'Cabinet created', 201);
    }

    public function cabinetsDestroy(Cabinet $cabinet)
    {
        $cabinet->delete();

        return $this->success(null, 'Cabinet deleted');
    }

    // ── Distribution Points ────────────────────────────────────────────────

    public function dpsIndex(Request $request)
    {
        $dps = DistributionPoint::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderBy('name')
            ->paginate($request->input('per_page', 25));

        return $this->success($dps);
    }

    public function dpsStore(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:191',
            'type'       => 'sometimes|in:fiber_hub,splice_tray,drop_point',
            'location'   => 'sometimes|nullable|string|max:191',
            'location_lat' => 'sometimes|nullable|numeric',
            'location_lng' => 'sometimes|nullable|numeric',
            'status'     => 'sometimes|in:active,inactive',
            'notes'      => 'sometimes|nullable|string',
        ]);

        $dp = DistributionPoint::create($data);

        return $this->success($dp, 'Distribution point created', 201);
    }

    public function dpsDestroy(DistributionPoint $distributionPoint)
    {
        $distributionPoint->delete();

        return $this->success(null, 'Distribution point deleted');
    }
}
