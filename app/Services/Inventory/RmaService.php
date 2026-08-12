<?php

namespace App\Services\Inventory;

use App\Models\Rma;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * RMA (Returns / Replacements / Repairs) workflow service.
 *
 * Lifecycle: requested -> approved -> processing -> completed, with rejected
 * and cancelled as terminal exits. Mirrors PurchaseOrderService::assertState.
 */
class RmaService
{
    public function __construct(protected ?Tenant $tenant = null)
    {
        $this->tenant = $this->tenant ?? Tenant::current();
    }

    public function getAll(array $filters = [])
    {
        return $this->baseQuery($filters)
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getById(Rma $rma): Rma
    {
        return $rma->fresh();
    }

    public function getStats(): array
    {
        $base = Rma::query();

        return [
            'total'     => (int) (clone $base)->count(),
            'open'      => (int) (clone $base)->open()->count(),
            'by_status' => (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')
                ->pluck('c', 'status')->all(),
        ];
    }

    public function createRma(array $data): Rma
    {
        $data['rma_number']   ??= $this->generateRmaNumber();
        $data['status']       ??= Rma::STATUS_REQUESTED;
        $data['type']         ??= Rma::TYPE_REPLACEMENT;
        $data['priority']     ??= Rma::PRIORITY_NORMAL;
        $data['requested_by'] ??= Auth::id();
        $data['requested_at'] ??= now();
        $data['created_by']   ??= Auth::id();
        $data['updated_by']   ??= Auth::id();

        return DB::transaction(function () use ($data) {
            $rma = Rma::create($data);

            Log::info('RMA created', ['rma' => $rma->rma_number, 'type' => $rma->type, 'by' => $rma->created_by]);

            return $rma;
        });
    }

    public function approve(Rma $rma, ?string $notes = null): Rma
    {
        $this->assertState($rma, [Rma::STATUS_REQUESTED]);

        return DB::transaction(function () use ($rma, $notes) {
            $rma->status      = Rma::STATUS_APPROVED;
            $rma->approved_by = $rma->approved_by ?? Auth::id();
            $rma->approved_at = now();
            $rma->updated_by  = Auth::id();
            if ($notes !== null) {
                $rma->notes = trim((string) $rma->notes . PHP_EOL . $notes);
            }
            $rma->save();

            Log::info('RMA approved', ['rma' => $rma->rma_number, 'by' => $rma->approved_by]);

            return $rma->fresh();
        });
    }

    public function reject(Rma $rma, ?string $reason = null): Rma
    {
        $this->assertState($rma, [Rma::STATUS_REQUESTED]);

        return DB::transaction(function () use ($rma, $reason) {
            $rma->status      = Rma::STATUS_REJECTED;
            $rma->resolved_by = $rma->resolved_by ?? Auth::id();
            $rma->updated_by  = Auth::id();
            if ($reason !== null) {
                $rma->notes = trim((string) $rma->notes . PHP_EOL . 'Rejected: ' . $reason);
            }
            $rma->save();

                        Log::info('RMA rejected', ['rma' => $rma->rma_number, 'by' => $rma->resolved_by]);

            return $rma->fresh();
        });
    }

    public function process(Rma $rma, ?string $trackingNumber = null): Rma
    {
        $this->assertState($rma, [Rma::STATUS_APPROVED]);

        return DB::transaction(function () use ($rma, $trackingNumber) {
            $rma->status           = Rma::STATUS_PROCESSING;
            $rma->tracking_number  = $trackingNumber ?? $rma->tracking_number;
            $rma->updated_by       = Auth::id();
            $rma->save();

            Log::info('RMA processing', ['rma' => $rma->rma_number, 'by' => Auth::id()]);

            return $rma->fresh();
        });
    }

    public function complete(Rma $rma, ?string $trackingNumber = null): Rma
    {
        $this->assertState($rma, [Rma::STATUS_PROCESSING]);

        return DB::transaction(function () use ($rma, $trackingNumber) {
            $rma->status       = Rma::STATUS_COMPLETED;
            $rma->resolved_by  = $rma->resolved_by ?? Auth::id();
            $rma->completed_at = now();
            $rma->updated_by   = Auth::id();
            if ($trackingNumber !== null) {
                $rma->tracking_number = $trackingNumber;
            }
            $rma->save();

            Log::info('RMA completed', ['rma' => $rma->rma_number, 'by' => $rma->resolved_by]);

            return $rma->fresh();
        });
    }

    public function cancel(Rma $rma, ?string $reason = null): Rma
    {
        $this->assertState($rma, [Rma::STATUS_REQUESTED, Rma::STATUS_APPROVED, Rma::STATUS_PROCESSING]);

        return DB::transaction(function () use ($rma, $reason) {
            $rma->status      = Rma::STATUS_CANCELLED;
            $rma->resolved_by = $rma->resolved_by ?? Auth::id();
            $rma->updated_by  = Auth::id();
            if ($reason !== null) {
                $rma->notes = trim((string) $rma->notes . PHP_EOL . 'Cancelled: ' . $reason);
            }
            $rma->save();

            Log::info('RMA cancelled', ['rma' => $rma->rma_number, 'by' => $rma->resolved_by]);

            return $rma->fresh();
        });
    }

    public function updateRma(Rma $rma, array $data): Rma
    {
        $rma->update($data);

        return $rma->fresh();
    }

    public function deleteRma(Rma $rma): void
    {
        $rma->delete();
    }

    /**
     * Guard a workflow transition so an RMA is never mutated out of an
     * unexpected state. Mirrors PurchaseOrderService::assertState.
     */
    protected function assertState(Rma $rma, array $allowed): void
    {
        if (! in_array($rma->status, $allowed, true)) {
            throw new RuntimeException(
                "Cannot transition RMA '{$rma->rma_number}' from status '{$rma->status}' "
                . '(allowed from: ' . implode(', ', $allowed) . ').'
            );
        }
    }

    protected function baseQuery(array $filters = [])
    {
        $query = Rma::query();

        if (! empty($filters['status']))       { $query->where('status', $filters['status']); }
        if (! empty($filters['priority']))    { $query->where('priority', $filters['priority']); }
        if (! empty($filters['type']))        { $query->where('type', $filters['type']); }
        if (! empty($filters['supplier_id'])) { $query->where('supplier_id', $filters['supplier_id']); }

        return $query;
    }

    protected function generateRmaNumber(): string
    {
        return 'RMA-' . ($this->tenant?->id ?? 0) . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
