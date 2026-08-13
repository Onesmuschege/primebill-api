<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collections\StoreDunningStepRequest;
use App\Http\Requests\Collections\UpdateDunningStepRequest;
use App\Models\Client;
use App\Models\DunningRun;
use App\Models\DunningStep;
use App\Models\Invoice;
use App\Services\Billing\DunningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CollectionsController
 *
 * Exposes the dunning/collections domain that already had a working engine
 * (DunningService + scheduled billing:run-dunning) but no REST API.
 *
 * All business logic is delegated to the existing DunningService — this
 * controller only handles request validation, authorization and response
 * shaping, with no duplicated dunning logic.
 *
 * Authorization is route-level (`permission:view collections` for reads,
 * `permission:manage dunning` for writes), matching FinanceController's
 * pattern. The FormRequests provide a second gate on `manage dunning`; no
 * model policies are required.
 */
class CollectionsController extends Controller
{
    public function __construct(protected DunningService $dunningService)
    {
    }

    // ── Invoice aging dashboard (the collections cockpit) ──────────

    /** GET /api/collections/aging */
    public function aging(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->dunningService->aging(),
        ]);
    }

    // ── Dunning step ladder (the escalation config) ────────────────

    /** GET /api/collections/dunning-steps */
    public function dunningSteps(Request $request): JsonResponse
    {
        $steps = DunningStep::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sequence')
            ->get();

        return response()->json(['success' => true, 'data' => $steps]);
    }

    /** GET /api/collections/dunning-steps/{step} */
    public function showDunningStep(DunningStep $step): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $step]);
    }

    /** POST /api/collections/dunning-steps */
    public function storeDunningStep(StoreDunningStepRequest $request): JsonResponse
    {
        $step = DunningStep::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Dunning step created',
            'data'    => $step,
        ], 201);
    }

    /** PUT/PATCH /api/collections/dunning-steps/{step} */
    public function updateDunningStep(UpdateDunningStepRequest $request, DunningStep $step): JsonResponse
    {
        $step->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Dunning step updated',
            'data'    => $step->fresh(),
        ]);
    }

    /** DELETE /api/collections/dunning-steps/{step} */
    public function destroyDunningStep(DunningStep $step): JsonResponse
    {
        $step->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dunning step removed',
        ]);
    }

    /** POST /api/collections/dunning-steps/reorder */
    public function reorderDunningSteps(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'steps'            => ['required', 'array', 'min:1'],
            'steps.*.id'       => ['required', 'integer', 'exists:dunning_steps,id'],
            'steps.*.sequence' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($validated['steps'] as $item) {
            DunningStep::where('id', $item['id'])->update(['sequence' => $item['sequence']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Dunning step order updated',
        ]);
    }

    // ── Dunning execution ──────────────────────────────────────────

    /** POST /api/collections/run */
    public function runNow(Request $request): JsonResponse
    {
        $summary = $this->dunningService->runNow((int) $request->input('limit', 200));

        return response()->json([
            'success' => true,
            'message' => 'Dunning run completed',
            'data'    => $summary,
        ]);
    }

    /** GET /api/collections/dunning-runs */
    public function dunningRuns(Request $request): JsonResponse
    {
        $query = DunningRun::query()->with(['client:id,first_name,last_name,email', 'invoice', 'step']);

        if ($invoiceId = $request->query('invoice_id')) {
            $query->where('invoice_id', $invoiceId);
        }
        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $runs = $query->latest('executed_at')->paginate($request->input('per_page', 25));

        return response()->json(['success' => true, 'data' => $runs]);
    }

    /** GET /api/collections/clients/{client}/dunning-runs */
    public function clientRuns(Client $client): JsonResponse
    {
        $runs = $client->dunningRuns()
            ->with(['invoice', 'step'])
            ->latest('executed_at')
            ->get();

        return response()->json(['success' => true, 'data' => $runs]);
    }

    /** GET /api/collections/invoices/{invoice}/dunning-runs */
    public function invoiceRuns(Invoice $invoice): JsonResponse
    {
        $runs = $invoice->dunningRuns()
            ->with(['client:id,first_name,last_name,email', 'step'])
            ->latest('executed_at')
            ->get();

        return response()->json(['success' => true, 'data' => $runs]);
    }
}
