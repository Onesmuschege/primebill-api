<?php

namespace App\Services\FieldOperations;

use App\Models\Client;
use App\Models\WorkOrder;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

class WorkOrderService
{
    public function getAllWorkOrders(array $filters = []): array
    {
        $query = WorkOrder::query()->with(['client', 'assignedTechnician', 'creator']);

        if (isset($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (isset($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['scheduled_from'])) {
            $query->where('scheduled_at', '>=', $filters['scheduled_from']);
        }

        if (isset($filters['scheduled_to'])) {
            $query->where('scheduled_at', '<=', $filters['scheduled_to']);
        }

        return $query->orderByDesc('scheduled_at')
            ->get()
            ->toArray();
    }

    public function createWorkOrder(Client $client, array $data): WorkOrder
    {
        $workOrder = new WorkOrder($data);
        $workOrder->tenant_id = $client->tenant_id;
        $workOrder->client_id = $client->id;
        $workOrder->created_by = Auth::id();
        $workOrder->work_order_number = $this->generateWorkOrderNumber();
        $workOrder->save();

        return $workOrder->load(['client', 'assignedTechnician', 'creator']);
    }

    public function updateWorkOrder(WorkOrder $workOrder, array $data): WorkOrder
    {
        $workOrder->update($data);
        return $workOrder->fresh(['client', 'assignedTechnician', 'creator']);
    }

    public function deleteWorkOrder(WorkOrder $workOrder): void
    {
        $workOrder->delete();
    }

    public function assignTechnician(WorkOrder $workOrder, User $technician): WorkOrder
    {
        $workOrder->update([
            'assigned_to' => $technician->id,
            'status' => 'dispatched',
        ]);

        return $workOrder->fresh(['client', 'assignedTechnician', 'creator']);
    }

    public function updateStatus(WorkOrder $workOrder, string $status, array $additionalData = []): WorkOrder
    {
        $data = ['status' => $status];

        switch ($status) {
            case 'in_progress':
                $data['started_at'] = now();
                break;
            case 'completed':
                $data['completed_at'] = now();
                if (isset($additionalData['completion_notes'])) {
                    $data['completion_notes'] = $additionalData['completion_notes'];
                }
                if (isset($additionalData['customer_signature'])) {
                    $data['customer_signature'] = $additionalData['customer_signature'];
                }
                if (isset($additionalData['photos'])) {
                    $data['photos'] = $additionalData['photos'];
                }
                if (isset($additionalData['completion_latitude'])) {
                    $data['completion_latitude'] = $additionalData['completion_latitude'];
                }
                if (isset($additionalData['completion_longitude'])) {
                    $data['completion_longitude'] = $additionalData['completion_longitude'];
                }
                break;
        }

        $workOrder->update($data);
        return $workOrder->fresh(['client', 'assignedTechnician', 'creator']);
    }

    public function getTechnicianWorkload(User $technician): array
    {
        $tenantId = Tenant::current()?->id;

        return [
            'scheduled' => WorkOrder::where('tenant_id', $tenantId)
                ->where('assigned_to', $technician->id)
                ->where('status', 'scheduled')
                ->count(),
            'in_progress' => WorkOrder::where('tenant_id', $tenantId)
                ->where('assigned_to', $technician->id)
                ->where('status', 'in_progress')
                ->count(),
            'completed_today' => WorkOrder::where('tenant_id', $tenantId)
                ->where('assigned_to', $technician->id)
                ->where('status', 'completed')
                ->whereDate('completed_at', today())
                ->count(),
            'total' => WorkOrder::where('tenant_id', $tenantId)
                ->where('assigned_to', $technician->id)
                ->whereIn('status', ['scheduled', 'dispatched', 'in_progress'])
                ->count(),
        ];
    }

    public function getStats(): array
    {
        $tenantId = Tenant::current()?->id ?? 'global';

        return [
            'total' => WorkOrder::where('tenant_id', $tenantId)->count(),
            'scheduled' => WorkOrder::where('tenant_id', $tenantId)->where('status', 'scheduled')->count(),
            'dispatched' => WorkOrder::where('tenant_id', $tenantId)->where('status', 'dispatched')->count(),
            'in_progress' => WorkOrder::where('tenant_id', $tenantId)->where('status', 'in_progress')->count(),
            'completed_today' => WorkOrder::where('tenant_id', $tenantId)->where('status', 'completed')->whereDate('completed_at', today())->count(),
            'cancelled' => WorkOrder::where('tenant_id', $tenantId)->where('status', 'cancelled')->count(),
            'by_type' => WorkOrder::where('tenant_id', $tenantId)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->get()
                ->map(fn($w) => ['type' => $w->type, 'count' => (int) $w->count])
                ->toArray(),
            'by_priority' => WorkOrder::where('tenant_id', $tenantId)
                ->selectRaw('priority, COUNT(*) as count')
                ->groupBy('priority')
                ->get()
                ->map(fn($w) => ['priority' => $w->priority, 'count' => (int) $w->count])
                ->toArray(),
        ];
    }

    private function generateWorkOrderNumber(): string
    {
        $tenantId = Tenant::current()?->id ?? '000';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -6));

        return "WO-{$tenantId}-{$date}-{$random}";
    }
}
