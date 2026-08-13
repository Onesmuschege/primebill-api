<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationEvent;
use App\Models\AutomationFailure;
use App\Models\AutomationRule;
use App\Services\Automation\Automation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Release 5 — Automation console API.
 *
 * Read-only event/job/failures streams plus rule management and a retry
 * entry point. All routes are guarded by auth:sanctum + tenant; the platform
 * console exposes them under /platform/automation.
 */
class AutomationController extends Controller
{
    use ApiResponse;

    public function __construct(protected Automation $automation)
    {
    }

    public function events(Request $request): JsonResponse
    {
        $query = AutomationEvent::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_class', 'like', '%'.$request->input('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->input('entity_id')))
            ->orderByDesc('id');

        $events = $query->paginate((int) $request->input('per_page', 20));

        return $this->success($events, 'OK');
    }

    public function jobs(Request $request): JsonResponse
    {
        $statuses = [
            'processing' => AutomationEvent::where('status', 'processing')->count(),
            'done'       => AutomationEvent::where('status', 'done')->count(),
            'failed'     => AutomationEvent::where('status', 'failed')->count(),
        ];

        $recent = AutomationEvent::whereIn('status', ['failed', 'processing'])
            ->orderByDesc('id')->limit(20)->get();

        return $this->success([
            'status_counts' => $statuses,
            'failed_jobs'   => AutomationFailure::unresolved()->orderByDesc('id')->limit(20)->get(),
            'recent'        => $recent,
        ], 'OK');
    }

    public function failures(Request $request): JsonResponse
    {
        $query = AutomationFailure::query()
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->input('event_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->input('entity_id')))
            ->when(! $request->boolean('resolved'), fn ($q) => $q->unresolved())
            ->orderByDesc('id');

        return $this->success($query->paginate((int) $request->input('per_page', 20)), 'OK');
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $failure = AutomationFailure::findOrFail($id);
        $ok = $this->automation->retry($failure);

        return $this->success(['retried' => $ok], $ok ? 'Retried' : 'Nothing to retry');
    }

    public function rules(Request $request): JsonResponse
    {
        $query = AutomationRule::query()
            ->when(! $request->boolean('with_inactive'), fn ($q) => $q->active());

        return $this->success($query->orderByDesc('priority')->get(), 'OK');
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'event_type'=> ['required', 'string'],
            'action'    => ['nullable', 'array'],
            'priority'  => ['integer'],
        ]);

        $rule = AutomationRule::create(array_merge($data, ['tenant_id' => optional($request->user())->tenant_id]));

        return $this->success($rule, 'Rule created', 201);
    }

    public function updateRule(Request $request, AutomationRule $rule): JsonResponse
    {
        $rule->update($request->only(['name', 'event_type', 'action', 'is_active', 'priority']));

        return $this->success($rule, 'Rule updated');
    }
}
