<?php

namespace App\Services\Plan;

use App\Models\Plan;
use App\Models\SystemLog;
use Illuminate\Http\Request;

class PlanService
{
    public function getAllPlans(Request $request)
    {
        $query = Plan::with('router');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return $query->orderBy('price', 'asc')->get();
    }

    public function createPlan(array $data, $userId)
    {
        $plan = Plan::create($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'created plan',
            'model'      => 'Plan',
            'model_id'   => $plan->id,
            'new_values' => $data,
        ]);

        return $plan;
    }

    public function updatePlan(Plan $plan, array $data, $userId)
    {
        $oldValues = $plan->toArray();
        $plan->update($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'updated plan',
            'model'      => 'Plan',
            'model_id'   => $plan->id,
            'old_values' => $oldValues,
            'new_values' => $data,
        ]);

        return $plan;
    }

    public function deletePlan(Plan $plan, $userId)
    {
        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'deleted plan',
            'model'      => 'Plan',
            'model_id'   => $plan->id,
            'old_values' => $plan->toArray(),
        ]);

        $plan->delete();
    }

    /**
     * Create an independent copy of a plan. Router_id and active status are
     * NOT copied: the clone starts inactive and unassigned so the operator
     * consciously decides what/who it applies before it can affect the
     * network.
     */
    public function duplicatePlan(Plan $plan, $userId): Plan
    {
        $copy = Plan::create([
            'name'           => $plan->name . ' (Copy)',
            'type'           => $plan->type,
            'speed_up'       => $plan->speed_up,
            'speed_down'     => $plan->speed_down,
            'burst_up'       => $plan->burst_up,
            'burst_down'     => $plan->burst_down,
            'fup_limit'      => $plan->fup_limit,
            'fup_speed_up'   => $plan->fup_speed_up,
            'fup_speed_down' => $plan->fup_speed_down,
            'validity_days'  => $plan->validity_days,
            'price'          => $plan->price,
            'router_id'      => null,
            'is_active'      => false,
        ]);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'duplicated plan',
            'model'      => 'Plan',
            'model_id'   => $copy->id,
            'old_values' => ['source_plan_id' => $plan->id],
            'new_values' => $copy->toArray(),
        ]);

        return $copy;
    }

    /**
     * Flip a plan's visibility flag (is_active).
     */
    public function togglePlanActive(Plan $plan, $userId): Plan
    {
        $plan->update(['is_active' => ! (bool) $plan->is_active]);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'toggled plan active',
            'model'      => 'Plan',
            'model_id'   => $plan->id,
            'old_values' => [],
            'new_values' => ['is_active' => $plan->is_active],
        ]);

        return $plan;
    }

    /**
     * Batch-edit bandwidth/FUP fields for a set of plans. Pricing, validity
     * and name are deliberately not touched — bulk operations must never
     * silently change money values.
     *
     * @param array  $ids   plan IDs
     * @param array  $data  allowed fields only
     * @param mixed  $userId
     */
    public function bulkUpdatePlans(array $ids, array $data, $userId): int
    {
        $allowed = ['speed_up', 'speed_down', 'burst_up', 'burst_down', 'fup_limit', 'fup_speed_up', 'fup_speed_down'];
        $payload = array_intersect_key($data, array_flip($allowed));

        if (empty($payload)) {
            return 0;
        }

        $count = Plan::whereIn('id', $ids)->update($payload);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'bulk updated plans',
            'model'      => 'Plan',
            'model_id'   => null,
            'new_values' => ['plan_ids' => $ids, 'payload' => $payload],
        ]);

        return $count;
    }
}
