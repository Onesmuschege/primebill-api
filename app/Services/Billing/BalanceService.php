<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;

class BalanceService
{
    public function getClientBalance(int $clientId): array
    {
        $totalInvoiced = (float) Invoice::where('client_id', $clientId)
            ->whereNotIn('status', ['cancelled'])
            ->sum('total');

        $totalPaid = (float) Payment::where('client_id', $clientId)
            ->where('status', 'completed')
            ->sum('amount');

        $outstanding = max(0, round($totalInvoiced - $totalPaid, 2));

        $unpaidInvoices = Invoice::where('client_id', $clientId)
            ->whereIn('status', ['unpaid', 'overdue', 'partial'])
            ->orderBy('due_date')
            ->get(['id', 'invoice_number', 'total', 'status', 'due_date']);

        return [
            'client_id'       => $clientId,
            'total_invoiced'  => $totalInvoiced,
            'total_paid'      => $totalPaid,
            'outstanding'     => $outstanding,
            'unpaid_invoices' => $unpaidInvoices,
        ];
    }
}
