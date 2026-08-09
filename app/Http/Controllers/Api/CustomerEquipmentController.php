<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\CustomerEquipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentHistory;

/**
 * CustomerEquipmentController
 *
 * Domain B — Customer Equipment (CPE): installed equipment, lifecycle
 * assignments and an audit trail of equipment movement.
 */
class CustomerEquipmentController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'customer-equipment' => [
            'model' => CustomerEquipment::class,
            'search' => ['serial_number', 'mac_address', 'type'],
            'rules' => [
                'client_id' => 'required|exists:clients,id',
                'type' => 'required|string|max:100',
            ],
        ],
        'equipment-assignments' => [
            'model' => EquipmentAssignment::class,
            'search' => ['status'],
            'rules' => ['customer_equipment_id' => 'required|exists:customer_equipment,id'],
        ],
        'equipment-histories' => [
            'model' => EquipmentHistory::class,
            'search' => ['action'],
        ],
    ];
}
