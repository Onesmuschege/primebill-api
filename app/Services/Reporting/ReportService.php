<?php

namespace App\Services\Reporting;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\SmsLog;
use App\Models\NetworkTraffic;
use App\Models\RadiusSession;
use App\Models\Expenditure;
use App\Models\InventoryItem;
use App\Models\FupLog;

class ReportService
{
    public function getIncomeReport(string $from, string $to): array
    {
        // Aggregate in SQL instead of loading all payments into PHP memory.
        $totals = Payment::whereBetween('created_at', [$from, $to])
                         ->where('status', 'completed')
                         ->selectRaw('SUM(amount) as total')
                         ->selectRaw('COUNT(*) as count')
                         ->selectRaw("SUM(CASE WHEN method = 'mpesa' THEN amount ELSE 0 END) as mpesa")
                         ->selectRaw("SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) as cash")
                         ->selectRaw("SUM(CASE WHEN method = 'bank' THEN amount ELSE 0 END) as bank")
                         ->first();

        $daily = Payment::whereBetween('created_at', [$from, $to])
                        ->where('status', 'completed')
                        ->selectRaw("TO_CHAR(created_at, 'YYYY-MM-DD') as day")
                        ->selectRaw('SUM(amount) as total')
                        ->groupByRaw("TO_CHAR(created_at, 'YYYY-MM-DD')")
                        ->orderByRaw("TO_CHAR(created_at, 'YYYY-MM-DD')")
                        ->pluck('total', 'day')
                        ->toArray();

        // Keep the last N payments for the detail table (paginated by caller if needed).
        $payments = Payment::whereBetween('created_at', [$from, $to])
                           ->where('status', 'completed')
                           ->with('client')
                           ->orderByDesc('created_at')
                           ->limit(500)
                           ->get();

        return [
            'total'       => (float) ($totals->total ?? 0),
            'count'       => (int) ($totals->count ?? 0),
            'by_method'   => [
                'mpesa' => (float) ($totals->mpesa ?? 0),
                'cash'  => (float) ($totals->cash ?? 0),
                'bank'  => (float) ($totals->bank ?? 0),
            ],
            'daily'       => $daily,
            'payments'    => $payments,
        ];
    }

public function getClientReport(string $from, string $to): array
    {
        $newClients = Client::whereBetween('created_at', [$from, $to])->count();
        $total      = Client::count();
        $active     = Client::where('status', 'active')->count();
        $suspended  = Client::where('status', 'suspended')->count();

        return [
            'new_clients' => $newClients,
            'total'       => $total,
            'active'      => $active,
            'suspended'   => $suspended,
            'by_status'   => Client::selectRaw('status, count(*) as count')
                                   ->groupBy('status')
                                   ->pluck('count', 'status')
                                   ->toArray(),
        ];
    }

    public function getInvoiceReport(string $from, string $to): array
    {
        $aggs = Invoice::whereBetween('created_at', [$from, $to])
                       ->selectRaw('COUNT(*) as total')
                       ->selectRaw("COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid")
                       ->selectRaw("COUNT(CASE WHEN status = 'unpaid' THEN 1 END) as unpaid")
                       ->selectRaw("COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue")
                       ->selectRaw("COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled")
                       ->selectRaw('SUM(total) as total_value')
                       ->selectRaw("SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) as collected")
                       ->selectRaw("SUM(CASE WHEN status IN ('unpaid','overdue') THEN total ELSE 0 END) as outstanding")
                       ->first();

        return [
            'total'       => (int) ($aggs->total ?? 0),
            'paid'        => (int) ($aggs->paid ?? 0),
            'unpaid'      => (int) ($aggs->unpaid ?? 0),
            'overdue'     => (int) ($aggs->overdue ?? 0),
            'cancelled'   => (int) ($aggs->cancelled ?? 0),
            'total_value' => (float) ($aggs->total_value ?? 0),
            'collected'   => (float) ($aggs->collected ?? 0),
            'outstanding' => (float) ($aggs->outstanding ?? 0),
        ];
    }

    public function getSmsReport(string $from, string $to): array
    {
        $aggs = SmsLog::whereBetween('created_at', [$from, $to])
                      ->selectRaw('COUNT(*) as total')
                      ->selectRaw("COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent")
                      ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed")
                      ->selectRaw("COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered")
                      ->first();

        $byGateway = SmsLog::whereBetween('created_at', [$from, $to])
                           ->selectRaw('gateway, COUNT(*) as count')
                           ->groupBy('gateway')
                           ->pluck('count', 'gateway')
                           ->toArray();

        return [
            'total'      => (int) ($aggs->total ?? 0),
            'sent'       => (int) ($aggs->sent ?? 0),
            'failed'     => (int) ($aggs->failed ?? 0),
            'delivered'  => (int) ($aggs->delivered ?? 0),
            'by_gateway' => $byGateway,
        ];
    }

    public function getNetworkReport(string $from, string $to): array
    {
        $aggs = RadiusSession::whereBetween('created_at', [$from, $to])
                             ->selectRaw('COUNT(*) as total_sessions')
                             ->selectRaw('COALESCE(SUM(bytes_out), 0) as total_download')
                             ->selectRaw('COALESCE(SUM(bytes_in), 0) as total_upload')
                             ->first();

        $top = RadiusSession::whereBetween('created_at', [$from, $to])
                            ->orderByDesc('bytes_out')
                            ->limit(10)
                            ->get(['id', 'username', 'bytes_out']);

        return [
            'total_sessions'  => (int) ($aggs->total_sessions ?? 0),
            'total_download'  => round(((float) ($aggs->total_download ?? 0)) / 1073741824, 2) . ' GB',
            'total_upload'    => round(((float) ($aggs->total_upload ?? 0)) / 1073741824, 2) . ' GB',
            'top_downloaders' => $top->map(fn($s) => [
                'username'   => $s->username,
                'downloaded' => round($s->bytes_out / 1073741824, 2) . ' GB',
            ])->values()->toArray(),
        ];
    }

    public function getInventoryReport(): array
    {
        return [
            'total_items'  => InventoryItem::count(),
            'total_value'  => InventoryItem::selectRaw('SUM(quantity * unit_cost) as value')->value('value'),
            'by_status'    => InventoryItem::selectRaw('status, count(*) as count')
                                           ->groupBy('status')
                                           ->pluck('count', 'status')
                                           ->toArray(),
            'by_category'  => InventoryItem::selectRaw('category, count(*) as count, SUM(quantity * unit_cost) as value')
                                           ->groupBy('category')
                                           ->get()
                                           ->toArray(),
            'low_stock'    => InventoryItem::whereColumn('quantity', '<=', 'low_stock_alert')->count(),
        ];
    }

    public function getExpenditureReport(string $from, string $to): array
    {
        $expenditures = Expenditure::whereBetween('date', [$from, $to])->get();

        return [
            'total'       => $expenditures->sum('amount'),
            'count'       => $expenditures->count(),
            'by_category' => $expenditures->groupBy('category')
                                          ->map(fn($g) => $g->sum('amount'))
                                          ->toArray(),
            'items'       => $expenditures,
        ];
    }
}
