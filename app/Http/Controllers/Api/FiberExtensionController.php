<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\FiberConnection;
use App\Models\OntEvent;
use App\Models\OntSignalHistory;

/**
 * FiberExtensionController
 *
 * Domain E — OLT/Fiber extensions: ONT signal history, ONT lifecycle events
 * and physical fiber connection paths.
 */
class FiberExtensionController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'ont-signal-histories' => [
            'model' => OntSignalHistory::class,
            'rules' => ['ont_id' => 'required|exists:onts,id'],
        ],
        'ont-events' => [
            'model' => OntEvent::class,
            'rules' => [
                'ont_id' => 'required|exists:onts,id',
                'event' => 'required|string|max:255',
            ],
        ],
        'fiber-connections' => [
            'model' => FiberConnection::class,
            'search' => ['serial_number', 'status'],
            'rules' => [
                'client_id' => 'nullable|exists:clients,id',
                'ont_id' => 'nullable|exists:onts,id',
                'pon_port_id' => 'nullable|exists:pon_ports,id',
            ],
        ],
    ];
}
