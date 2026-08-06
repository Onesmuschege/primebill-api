<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;

class LogController extends Controller
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    // GET /api/logs
    public function index(Request $request)
    {
        $query = SystemLog::with('user');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        if ($request->has('model')) {
            $query->where('model', $request->model);
        }

        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->orderBy('created_at', 'desc')
                      ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    // GET /api/logs/{id}
    public function show(SystemLog $systemLog)
    {
        $systemLog->load('user');

        return response()->json([
            'success' => true,
            'data'    => $systemLog,
        ]);
    }

    // GET /api/logs/export
    public function export(Request $request)
    {
        $query = SystemLog::with('user');

        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        $csv = "ID,User,Action,Model,Model ID,IP Address,Date\n";

        foreach ($logs as $log) {
            $csv .= implode(',', [
                $log->id,
                $log->user?->name ?? 'System',
                $log->action,
                $log->model ?? '',
                $log->model_id ?? '',
                $log->ip_address ?? '',
                $log->created_at->format('Y-m-d H:i:s'),
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=system_logs.csv',
        ]);
    }

    // GET /api/logs/stats - Get audit statistics
    public function stats(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);

        $stats = $this->auditService->getStats(
            \Carbon\Carbon::parse($request->from),
            \Carbon\Carbon::parse($request->to)
        );

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }
}
