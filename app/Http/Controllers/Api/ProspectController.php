<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prospect\StoreProspectRequest;
use App\Http\Requests\Prospect\UpdateProspectRequest;
use App\Models\Prospect;
use App\Services\Lead\ProspectService;
use Illuminate\Http\Request;

class ProspectController extends Controller
{
    protected ProspectService $prospectService;

    public function __construct(ProspectService $prospectService)
    {
        $this->prospectService = $prospectService;
    }

    // GET /api/prospects
    public function index(Request $request)
    {
        $prospects = $this->prospectService->getAllProspects($request);

        return response()->json([
            'success' => true,
            'data'    => $prospects,
        ]);
    }

    // GET /api/prospects/stats
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => $this->prospectService->getStats(),
        ]);
    }

    // POST /api/prospects
    public function store(StoreProspectRequest $request)
    {
        $prospect = $this->prospectService->createProspect(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Prospect created successfully',
            'data'    => $prospect,
        ], 201);
    }

    // GET /api/prospects/{prospect}
    public function show(Prospect $prospect)
    {
        $prospect->load(['assignedTo', 'lead', 'addresses']);

        return response()->json([
            'success' => true,
            'data'    => $prospect,
        ]);
    }

    // PUT /api/prospects/{prospect}
    public function update(UpdateProspectRequest $request, Prospect $prospect)
    {
        $prospect = $this->prospectService->updateProspect(
            $prospect,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Prospect updated successfully',
            'data'    => $prospect,
        ]);
    }

    // DELETE /api/prospects/{prospect}
    public function destroy(Request $request, Prospect $prospect)
    {
        $this->prospectService->deleteProspect($prospect, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Prospect deleted successfully',
        ]);
    }

    // POST /api/prospects/{prospect}/advance
    public function advance(Request $request, Prospect $prospect)
    {
        $request->validate([
            'pipeline_stage' => ['required', 'string', 'in:new,negotiation,survey_scheduled,survey_completed,installation_scheduled,won,lost'],
        ]);

        $prospect = $this->prospectService->advancePipeline(
            $prospect,
            $request->pipeline_stage,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Prospect pipeline stage updated',
            'data'    => $prospect,
        ]);
    }

    // POST /api/prospects/{prospect}/won
    public function markWon(Request $request, Prospect $prospect)
    {
        $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
        ]);

        $prospect = $this->prospectService->markAsWon(
            $prospect,
            $request->client_id,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Prospect marked as won',
            'data'    => $prospect,
        ]);
    }

    // POST /api/prospects/{prospect}/lost
    public function markLost(Request $request, Prospect $prospect)
    {
        $request->validate([
            'lost_reason' => ['required', 'string'],
        ]);

        $prospect = $this->prospectService->markAsLost(
            $prospect,
            $request->lost_reason,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Prospect marked as lost',
            'data'    => $prospect,
        ]);
    }
}
