<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\CustomerFeedback;
use App\Models\CustomerInteraction;
use App\Models\CustomerJourneyEvent;
use App\Models\CustomerSatisfaction;

/**
 * CustomerExperienceController
 *
 * Domain J — Customer Experience: interactions, lifecycle journey events,
 * feedback and satisfaction (CSAT/NPS) tracking.
 */
class CustomerExperienceController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'customer-interactions' => [
            'model' => CustomerInteraction::class,
            'search' => ['type', 'direction'],
            'rules' => ['client_id' => 'required|exists:clients,id'],
        ],
        'customer-journey-events' => [
            'model' => CustomerJourneyEvent::class,
            'search' => ['event', 'category'],
            'rules' => [
                'client_id' => 'required|exists:clients,id',
                'event' => 'required|string|max:255',
            ],
        ],
        'customer-feedback' => [
            'model' => CustomerFeedback::class,
            'search' => ['type', 'status'],
            'rules' => ['client_id' => 'required|exists:clients,id'],
        ],
        'customer-satisfactions' => [
            'model' => CustomerSatisfaction::class,
            'search' => ['type'],
            'rules' => ['client_id' => 'required|exists:clients,id'],
        ],
    ];
}
