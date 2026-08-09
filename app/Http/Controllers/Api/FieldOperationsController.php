<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\TechnicianAvailability;
use App\Models\TechnicianLocation;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderChecklist;
use App\Models\WorkOrderPart;
use App\Models\WorkOrderStatusHistory;
use App\Models\WorkOrderTemplate;

/**
 * FieldOperationsController
 *
 * Domain L — Field Operations extensions: work order templates, checklists,
 * parts, attachments, status history and technician location/availability.
 */
class FieldOperationsController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'work-order-templates' => [
            'model' => WorkOrderTemplate::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'work-order-checklists' => [
            'model' => WorkOrderChecklist::class,
            'search' => ['title'],
            'rules' => ['title' => 'required|string|max:255'],
        ],
        'work-order-parts' => [
            'model' => WorkOrderPart::class,
            'search' => ['part_name', 'part_number'],
            'rules' => ['work_order_id' => 'required|exists:work_orders,id'],
        ],
        'work-order-attachments' => [
            'model' => WorkOrderAttachment::class,
            'search' => ['file_name', 'category'],
            'rules' => ['work_order_id' => 'required|exists:work_orders,id'],
        ],
        'work-order-status-histories' => [
            'model' => WorkOrderStatusHistory::class,
            'search' => ['to_status'],
            'rules' => ['work_order_id' => 'required|exists:work_orders,id'],
        ],
        'technician-locations' => [
            'model' => TechnicianLocation::class,
            'search' => ['status'],
            'rules' => [
                'user_id' => 'required|exists:users,id',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
            ],
        ],
        'technician-availabilities' => [
            'model' => TechnicianAvailability::class,
            'search' => ['status'],
            'rules' => [
                'user_id' => 'required|exists:users,id',
                'available_date' => 'required|date',
            ],
        ],
    ];
}
