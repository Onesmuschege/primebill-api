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
     * End-to-end prospect → client conversion (P2 — CRM workflow).
     *
     * Creates the Client record (and, when a plan is supplied, the initial
     * ClientAccount with generated PPP credentials), then marks the prospect
     * (and its originating lead, if any) as won/converted and links the chain
     * lead → prospect → client for CRM reporting.
     *
     * @param  array{id?:int, plan_id?:int, username?:string, password?:string, notes?:string} $data
     * @return array{client:Client, account:?ClientAccount}
     */
    public function convertToClient(Prospect $prospect, array $data, $userId): array
    {
        if ($prospect->status === 'converted') {
            throw new \InvalidArgumentException('Prospect has already been converted.');
        }

        $plan = null;
        if (!empty($data['plan_id'])) {
            $plan = Plan::findOrFail($data['plan_id']);
        }

        [$client, $account] = DB::transaction(function () use ($prospect, $data, $plan, $userId) {
            // 1. Create the client from prospect identity data. Explicit data
            //    overrides always win over prospect fields.
            $client = $this->clientService->createClient([
                'first_name' => $data['first_name'] ?? $prospect->first_name,
                'last_name'  => $data['last_name'] ?? $prospect->last_name,
                'email'      => $data['email'] ?? $prospect->email,
                'phone'      => $data['phone'] ?? $prospect->phone,
                'alt_phone'  => $data['alt_phone'] ?? $prospect->alt_phone,
                'address'    => $data['address'] ?? $prospect->address,
                'town'       => $data['town'] ?? $prospect->town,
                'county'     => $data['county'] ?? $prospect->county,
                'status'     => 'active',
                'notes'      => $data['notes'] ?? null,
            ], $userId);

            // 2. Optionally create the initial service account with the plan
            //    the prospect expressed interest in during the sales pipeline.
            $account = null;
            if ($plan) {
                $username = $data['username']
                    ?? strtolower(Str::slug($client->first_name . $client->last_name)) . $client->id;
                $password = $data['password'] ?? Str::random(10);

                $account = ClientAccount::create([
                    'tenant_id' => $client->tenant_id,
                    'client_id' => $client->id,
                    'plan_id'   => $plan->id,
                    'username'  => $username,
                    'password'  => $password,
                    'type'      => 'prepaid',
                    'status'    => 'pending',
                    'service_state' => ClientAccount::STATE_PENDING,
                    'access_method' => match ($prospect->installation_type) {
                        'fiber'   => ClientAccount::ACCESS_DHCP,
                        'wireless'=> ClientAccount::ACCESS_PPPOE,
                        'pppoe'   => ClientAccount::ACCESS_PPPOE,
                        default   => ClientAccount::ACCESS_PPPOE,
                    },
                ]);

                SystemLog::create([
                    'user_id'  => $userId,
                    'action'   => 'created client account from prospect conversion',
                    'model'    => 'ClientAccount',
                    'model_id' => $account->id,
                    'new_values' => [
                        'client_id' => $client->id,
                        'plan_id'   => $plan->id,
                        'prospect_id' => $prospect->id,
                    ],
                ]);
            }

            return [$client, $account];
        });

        // 3. Mark the prospect won — reuses the authoritative marker so lead
        //    linkage and audit stay in one place.
        $this->markAsWon($prospect, $client->id, $userId);

        return [
            'client'  => $client,
            'account' => $account,
        ];
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
