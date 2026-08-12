<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiberConnection;
use App\Models\FiberRoute;
use App\Models\FiberSplitter;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PonPort;
use Illuminate\Http\Request;

/**
 * Fiber capacity analytics (Release 4).
 *
 * Reports OLT / PON-port / ONT utilization and the splitter+route
 * inventory so operators can see headroom without drilling into KPIs
 * only. Purely read-only; no writes.
 */
class FiberCapacityController extends Controller
{
    public function capacity(Request $request)
    {
        $oltId = $request->integer('olt_id') ?: null;

        $olts = Olt::query()
            ->when($oltId, fn ($q) => $q->whereKey($oltId))
            ->get();

        $ponPorts = PonPort::query()
            ->when($oltId, fn ($q) => $q->where('olt_id', $oltId))
            ->with('olt:id,name')
            ->get();

        $ontCount = Ont::query()
            ->when($oltId, fn ($q) => $q->where('olt_id', $oltId))
            ->count();

        $oltRows = $olts->map(function (Olt $olt) {
            $ports = PonPort::query()->where('olt_id', $olt->id)->get(['id', 'status', 'max_onts', 'registered_onts']);
            $total = $ports->count();
            $active = $ports->where('status', 'active')->count();

            return [
                'id'                 => $olt->id,
                'name'               => $olt->name,
                'status'             => $olt->status,
                'total_pon_ports'    => $total,
                'active_pon_ports'   => $active,
                'port_utilization_pct' => $total > 0 ? round(($active / $total) * 100, 1) : 0,
                'max_ont_capacity'   => (int) $ports->sum('max_onts'),
                'ont_count'          => (int) Ont::query()->where('olt_id', $olt->id)->count(),
            ];
        })->values();

        $ponRows = $ponPorts->map(fn (PonPort $p) => [
            'id'              => $p->id,
            'name'            => $p->name,
            'olt'             => $p->olt?->name,
            'status'          => $p->status,
            'max_onts'        => (int) $p->max_onts,
            'registered_onts' => (int) $p->registered_onts,
            'utilization_pct' => $p->max_onts > 0 ? round(($p->registered_onts / $p->max_onts) * 100, 1) : 0,
        ])->values();

        $routeRows = FiberRoute::query()->get(['id', 'name', 'source', 'destination', 'length_km', 'cable_type', 'status'])
            ->map(function (FiberRoute $r) {
                return [
                    'id'               => $r->id,
                    'name'             => $r->name,
                    'source'           => $r->source,
                    'destination'      => $r->destination,
                    'length_km'        => $r->length_km,
                    'cable_type'       => $r->cable_type,
                    'status'           => $r->status,
                    'connection_count' => (int) FiberConnection::query()->where('fiber_route_id', $r->id)->count(),
                ];
            })->values();

        $splitterRows = FiberSplitter::query()->get(['id', 'name', 'split_ratio', 'status', 'location'])
            ->map(fn (FiberSplitter $s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'split_ratio'=> $s->split_ratio,
                'status'     => $s->status,
                'location'   => $s->location,
            ])->values();

        $maxOntCapacity = (int) $ponPorts->sum('max_onts');

        $data = [
            'summary' => [
                'olts'              => $olts->count(),
                'pon_ports'         => $ponPorts->count(),
                'registered_onts'   => $ontCount,
                'max_ont_capacity'  => $maxOntCapacity,
                'ont_utilization_pct' => $maxOntCapacity > 0 ? round(($ontCount / $maxOntCapacity) * 100, 1) : 0,
                'routes'            => FiberRoute::count(),
                'splitters'         => FiberSplitter::count(),
            ],
            'olts'      => $oltRows,
            'pon_ports' => $ponRows,
            'routes'    => $routeRows,
            'splitters' => $splitterRows,
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }
}