<?php

namespace App\Services\Lead;

use App\Models\Lead;
use App\Models\Prospect;
use App\Models\SystemLog;
use Illuminate\Http\Request;

class LeadService
{
    /**
     * Get filtered and paginated list of leads.
     */
    public function getAllLeads(Request $request)
    {
        $query = Lead::query();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by source
        if ($request->filled('source')) {
            $query->where('source', $request->source);
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

        return $query->with('assignedTo')
                     ->orderBy('created_at', 'desc')
                     ->paginate($request->per_page ?? 15);
    }

    /**
     * Create a lead and record activity log.
     */
    public function createLead(array $data, $userId)
    {
        $lead = Lead::create($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'created lead',
            'model'      => 'Lead',
            'model_id'   => $lead->id,
            'new_values' => $data,
        ]);

        return $lead;
    }

    /**
     * Update a lead and record modification log.
     */
    public function updateLead(Lead $lead, array $data, $userId)
    {
        $oldValues = $lead->toArray();
        $lead->update($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'updated lead',
            'model'      => 'Lead',
            'model_id'   => $lead->id,
            'old_values' => $oldValues,
            'new_values' => $data,
        ]);

        return $lead;
    }

    /**
     * Delete a lead and capture snapshot to logs.
     */
    public function deleteLead(Lead $lead, $userId)
    {
        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'deleted lead',
            'model'      => 'Lead',
            'model_id'   => $lead->id,
            'old_values' => $lead->toArray(),
        ]);

        $lead->delete();
    }

    /**
     * Convert a lead to a prospect (moves to sales pipeline).
     */
    public function convertToProspect(Lead $lead, array $data, $userId)
    {
        $prospect = Prospect::create([
            'lead_id'             => $lead->id,
            'first_name'          => $data['first_name'] ?? $lead->first_name,
            'last_name'           => $data['last_name'] ?? $lead->last_name,
            'email'               => $data['email'] ?? $lead->email,
            'phone'               => $data['phone'] ?? $lead->phone,
            'alt_phone'           => $data['alt_phone'] ?? $lead->alt_phone,
            'address'             => $data['address'] ?? $lead->address,
            'town'                => $data['town'] ?? $lead->town,
            'county'              => $data['county'] ?? $lead->county,
            'gps_lat'             => $data['gps_lat'] ?? $lead->gps_lat,
            'gps_lng'             => $data['gps_lng'] ?? $lead->gps_lng,
            'interested_package'  => $data['interested_package'] ?? null,
            'installation_type'   => $data['installation_type'] ?? null,
            'installation_fee_quoted' => $data['installation_fee_quoted'] ?? null,
            'notes'               => $data['notes'] ?? $lead->notes,
            'assigned_to'         => $data['assigned_to'] ?? $lead->assigned_to,
        ]);

        // Mark the lead as qualified
        $lead->update([
            'status'       => 'qualified',
            'qualified_at' => now(),
        ]);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'converted lead to prospect',
            'model'      => 'Lead',
            'model_id'   => $lead->id,
            'new_values' => ['prospect_id' => $prospect->id],
        ]);

        return $prospect;
    }

    /**
     * Mark a lead as lost with a reason.
     */
    public function markAsLost(Lead $lead, $reason, $userId)
    {
        $lead->update([
            'status'      => 'lost',
            'lost_reason' => $reason,
        ]);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'marked lead as lost',
            'model'      => 'Lead',
            'model_id'   => $lead->id,
            'old_values' => ['status' => 'qualified'],
            'new_values' => ['status' => 'lost', 'lost_reason' => $reason],
        ]);

        return $lead;
    }

    /**
     * Get lead statistics for the dashboard.
     */
    public function getStats()
    {
        return [
            'total'     => Lead::count(),
            'new'       => Lead::where('status', 'new')->count(),
            'contacted' => Lead::where('status', 'contacted')->count(),
            'qualified' => Lead::where('status', 'qualified')->count(),
            'converted' => Lead::where('status', 'converted')->count(),
            'lost'      => Lead::where('status', 'lost')->count(),
        ];
    }
}
