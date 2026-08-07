<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\StoreLeadRequest;
use App\Http\Requests\Lead\UpdateLeadRequest;
use App\Models\Lead;
use App\Services\Lead\LeadService;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    protected LeadService $leadService;

    public function __construct(LeadService $leadService)
    {
        $this->leadService = $leadService;
    }

    // GET /api/leads
    public function index(Request $request)
    {
        $leads = $this->leadService->getAllLeads($request);

        return response()->json([
            'success' => true,
            'data'    => $leads,
        ]);
    }

    // GET /api/leads/stats
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => $this->leadService->getStats(),
        ]);
    }

    // POST /api/leads
    public function store(StoreLeadRequest $request)
    {
        $lead = $this->leadService->createLead(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead created successfully',
            'data'    => $lead,
        ], 201);
    }

    // GET /api/leads/{lead}
    public function show(Lead $lead)
    {
        $lead->load(['assignedTo', 'prospect', 'addresses']);

        return response()->json([
            'success' => true,
            'data'    => $lead,
        ]);
    }

    // PUT /api/leads/{lead}
    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        $lead = $this->leadService->updateLead(
            $lead,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully',
            'data'    => $lead,
        ]);
    }

    // DELETE /api/leads/{lead}
    public function destroy(Request $request, Lead $lead)
    {
        $this->leadService->deleteLead($lead, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully',
        ]);
    }

    // POST /api/leads/{lead}/convert
    public function convert(Request $request, Lead $lead)
    {
        $request->validate([
            'interested_package'    => ['nullable', 'string', 'max:100'],
            'installation_type'     => ['nullable', 'string', 'in:fiber,wireless,pppoe'],
            'installation_fee_quoted' => ['nullable', 'numeric', 'min:0'],
            'notes'                 => ['nullable', 'string'],
            'assigned_to'           => ['nullable', 'exists:users,id'],
        ]);

        $prospect = $this->leadService->convertToProspect(
            $lead,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead converted to prospect successfully',
            'data'    => $prospect,
        ], 201);
    }

    // POST /api/leads/{lead}/lost
    public function markLost(Request $request, Lead $lead)
    {
        $request->validate([
            'lost_reason' => ['required', 'string'],
        ]);

        $lead = $this->leadService->markAsLost(
            $lead,
            $request->lost_reason,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead marked as lost',
            'data'    => $lead,
        ]);
    }
}
