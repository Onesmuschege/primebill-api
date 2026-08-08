<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\SystemLog;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;

class InvoiceService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected SettingsService $settingsService,
        protected TaxEngineService $taxEngineService,
        protected DiscountService $discountService
    ) {}

    public function getAllInvoices(Request $request)
    {
        $query = Invoice::with('client');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($q) use ($search) {
                      $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        return $query->orderBy('created_at', 'desc')
                     ->paginate($request->per_page ?? 15);
    }

    public function createInvoice(array $data, $userId): Invoice
    {
        $data['invoice_number'] = $this->generateInvoiceNumber();

        // Compute subtotal (amount minus any discount)
        $amount = (float) ($data['amount'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);
        $subtotal = round(max(0, $amount - $discount), 2);

        $data['discount'] = $discount;
        $data['subtotal'] = $subtotal;
        $data['tax']      = 0;
        $data['total']    = $subtotal;
        $data['created_by'] = $userId;
        $data['status']   = $data['status'] ?? 'unpaid';

        $invoice = Invoice::create($data);

        // Apply coupon if provided (reduces subtotal before tax)
        if (!empty($data['coupon_code'])) {
            $this->discountService->applyCoupon($invoice, $data['coupon_code'], $invoice->client_id);
            $invoice->refresh();
        }

        // Apply tax lines across all active rates on the final subtotal
        $taxBase = (float) $invoice->subtotal;
        $tax = $this->taxEngineService->applyTaxLines($invoice, $taxBase);
        $invoice->update([
            'tax'   => $tax,
            'total' => round($taxBase + $tax, 2),
        ]);

        // Post debit entry to ledger
        $this->ledgerService->postInvoiceDebit($invoice, $userId);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'created invoice',
            'model'      => 'Invoice',
            'model_id'   => $invoice->id,
            'new_values' => $data,
        ]);

        return $invoice->load('client', 'taxLines', 'discountLines');
    }

    public function updateInvoice(Invoice $invoice, array $data, $userId): Invoice
    {
        $oldValues = $invoice->toArray();

        // Recalculate total if amount, discount, or tax changed
        if (isset($data['amount']) || isset($data['discount']) || isset($data['tax'])) {
            $amount   = (float) ($data['amount'] ?? $invoice->amount);
            $discount = (float) ($data['discount'] ?? $invoice->discount);
            $subtotal = round(max(0, $amount - $discount), 2);
            $tax      = (float) ($data['tax'] ?? $invoice->tax);
            $total    = round($subtotal + $tax, 2);

            $data['discount'] = $discount;
            $data['subtotal'] = $subtotal;
            $data['tax']      = $tax;
            $data['total']    = $total;
        }

        $invoice->update($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'updated invoice',
            'model'      => 'Invoice',
            'model_id'   => $invoice->id,
            'old_values' => $oldValues,
            'new_values' => $data,
        ]);

        return $invoice->fresh('client', 'taxLines', 'discountLines');
    }

    public function deleteInvoice(Invoice $invoice, $userId): void
    {
        // Reverse the ledger debit posted at creation
        $this->ledgerService->postInvoiceReversal($invoice, $userId);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'deleted invoice',
            'model'      => 'Invoice',
            'model_id'   => $invoice->id,
            'old_values' => $invoice->toArray(),
        ]);

        $invoice->delete();
    }

    public function markAsPaid(Invoice $invoice, $userId): Invoice
    {
        $invoice->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        SystemLog::create([
            'user_id'  => $userId,
            'action'   => 'marked invoice as paid',
            'model'    => 'Invoice',
            'model_id' => $invoice->id,
        ]);

        return $invoice;
    }

    public function markOverdueInvoices(): int
    {
        // Only mark unpaid invoices that are past due date
        return Invoice::where('status', 'unpaid')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);
    }

    public function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $year   = date('Y');

        // Lock to prevent race conditions under concurrent requests
        $last = Invoice::whereYear('created_at', $year)
                       ->orderBy('id', 'desc')
                       ->lockForUpdate()
                       ->first();

        $number = $last
            ? (intval(substr($last->invoice_number, -6)) + 1)
            : 1;

        return $prefix . '-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    public function bulkGenerate(array $clientIds, $userId): int
    {
        $count = 0;

        foreach ($clientIds as $clientId) {
            $client = Client::with('accounts.plan')->find($clientId);

            if (!$client) continue;

            foreach ($client->accounts as $account) {
                if (!$account->plan) continue;

                // Skip if an unpaid invoice already exists for this account's plan
                $alreadyExists = Invoice::where('client_id', $clientId)
                    ->whereIn('status', ['unpaid', 'overdue'])
                    ->exists();

                if ($alreadyExists) continue;

                $this->createInvoice([
                    'client_id' => $clientId,
                    'amount'    => $account->plan->price,
                    'due_date'  => now()->addDays(7)->toDateString(),
                    'status'    => 'unpaid',
                ], $userId);

                $count++;
            }
        }

        return $count;
    }

    /**
     * @deprecated Use TaxEngineService::calculateTax() instead.
     */
    public function calculateTax(float $amount): float
    {
        return $this->taxEngineService->calculateTax($amount);
    }
}
