<?php

namespace App\Services\Lead;

use App\Models\Prospect;
use App\Models\SystemLog;
use Illuminate\Http\Request;

class ProspectService
{
    /**
     * Get filtered and paginated list of prospects.
     */
    public function getAllProspects(Request $request)
    {
        $query = Prospect::query();

        // Filter by pipeline stage
        if ($request->filled('pipeline_stage')) {
            $query->where('pipeline_stage', $request->pipeline_stage);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by assigned user
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // Search by name, phone or email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->with(['assignedTo', 'lead'])
                     ->orderBy('created_at', 'desc')
                     ->paginate($request->per_page ?? 15);
    }

    /**
     * Create a prospect directly (without a lead).
     */
    public function createProspect(array $data, $userId)
    {
        $prospect = Prospect::create($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'created prospect',
            'model'      => 'Prospect',
            'model_id'   => $prospect->id,
            'new_values' => $data,
        ]);

        return $prospect;
    }

    /**
     * Update a prospect and record modification log.
     */
    public function updateProspect(Prospect $prospect, array $data, $userId)
    {
        $oldValues = $prospect->toArray();
        $prospect->update($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'updated prospect',
            'model'      => 'Prospect',
            'model_id'   => $prospect->id,
            'old_values' => $oldValues,
            'new_values' => $data,
        ]);

        return $prospect;
    }

    /**
     * Delete a prospect and capture snapshot to logs.
     */
    public function deleteProspect(Prospect $prospect, $userId)
    {
        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'deleted prospect',
            'model'      => 'Prospect',
            'model_id'   => $prospect->id,
            'old_values' => $prospect->toArray(),
        ]);

        $prospect->delete();
    }

    /**
     * Advance a prospect through the pipeline.
     */
    public function advancePipeline(Prospect $prospect, string $stage, $userId)
    {
        $allowedStages = Prospect::PIPELINE_STAGES;
        $currentIndex = array_search($prospect->pipeline_stage, $allowedStages);
        $targetIndex = array_search($stage, $allowedStages);

        if ($targetIndex === false) {
            throw new \InvalidArgumentException("Invalid pipeline stage: {$stage}");
        }

        // Allow skipping stages forward but not backward
        abort_if($targetIndex < $currentIndex, 422, 'Cannot move prospect backwards in pipeline');

        $oldStage = $prospect->pipeline_stage;
        $prospect->update(['pipeline_stage' => $stage]);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'advanced prospect pipeline',
            'model'      => 'Prospect',
            'model_id'   => $prospect->id,
            'old_values' => ['pipeline_stage' => $oldStage],
            'new_values' => ['pipeline_stage' => $stage],
        ]);

        return $prospect;
    }

    /**
     * Mark a prospect as won (converted to client).
     */
    public function markAsWon(Prospect $prospect, $clientId, $userId)
    {
        $prospect->update([
            'pipeline_stage'        => 'won',
            'status'                => 'converted',
            'converted_at'          => now(),
            'converted_to_client_id' => $clientId,
        ]);

        // If this prospect came from a lead, update the lead too
        if ($prospect->lead) {
            $prospect->lead->update([
                'status'               => 'converted',
                'converted_at'         => now(),
                'converted_to_client_id' => $clientId,
            ]);
        }

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'marked prospect as won',
            'model'      => 'Prospect',
            'model_id'   => $prospect->id,
            'new_values' => [
                'pipeline_stage' => 'won',
                'status' => 'converted',
                'converted_to_client_id' => $clientId,
            ],
        ]);

        return $prospect;
    }

    /**
     * Mark a prospect as lost with a reason.
     */
    public function markAsLost(Prospect $prospect, $reason, $userId)
    {
        $prospect->update([
            'pipeline_stage' => 'lost',
            'status'         => 'lost',
            'lost_reason'    => $reason,
        ]);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'marked prospect as lost',
            'model'      => 'Prospect',
            'model_id'   => $prospect->id,
            'old_values' => ['pipeline_stage' => 'won', 'status' => 'active'],
            'new_values' => ['pipeline_stage' => 'lost', 'status' => 'lost', 'lost_reason' => $reason],
        ]);

        return $prospect;
    }

    /**
     * Get prospect statistics for the dashboard.
     */
    public function getStats()
    {
        return [
            'total'    => Prospect::count(),
            'active'   => Prospect::where('status', 'active')->count(),
            'won'      => Prospect::where('status', 'converted')->count(),
            'lost'     => Prospect::where('status', 'lost')->count(),
            'by_stage' => [
                'new'                   => Prospect::where('pipeline_stage', 'new')->count(),
                'negotiation'           => Prospect::where('pipeline_stage', 'negotiation')->count(),
                'survey_scheduled'      => Prospect::where('pipeline_stage', 'survey_scheduled')->count(),
                'survey_completed'      => Prospect::where('pipeline_stage', 'survey_completed')->count(),
                'installation_scheduled' => Prospect::where('pipeline_stage', 'installation_scheduled')->count(),
            ],
        ];
    }
}
