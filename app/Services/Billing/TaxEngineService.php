<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceTaxLine;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

class TaxEngineService
{
    /**
     * Get the active tax rates for the current tenant.
     */
    public function getActiveRates(): \Illuminate\Database\Eloquent\Collection
    {
        return TaxRate::where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the default tax rate (or null if none configured).
     */
    public function getDefaultRate(): ?TaxRate
    {
        return TaxRate::where('is_active', true)
            ->where('is_default', true)
            ->first()
            ?: TaxRate::where('is_active', true)->first();
    }

    /**
     * Calculate tax for a given base amount using the default rate.
     * Backwards-compatible with the old flat calculateTax().
     */
    public function calculateTax(float $amount): float
    {
        $rate = $this->getDefaultRate();

        if (!$rate || (float) $rate->rate <= 0) {
            return 0;
        }

        if ($rate->type === 'fixed') {
            return round((float) $rate->rate, 2);
        }

        return round($amount * ((float) $rate->rate / 100), 2);
    }

    /**
     * Calculate tax breakdown for a base amount across all active rates.
     *
     * @return array<int, array{name: string, code: ?string, rate: float, base_amount: float, tax_amount: float}>
     */
    public function calculateTaxBreakdown(float $baseAmount): array
    {
        $lines = [];

        foreach ($this->getActiveRates() as $rate) {
            $taxAmount = $rate->type === 'fixed'
                ? round((float) $rate->rate, 2)
                : round($baseAmount * ((float) $rate->rate / 100), 2);

            $lines[] = [
                'name'        => $rate->name,
                'code'        => $rate->code,
                'rate'        => (float) $rate->rate,
                'base_amount' => $baseAmount,
                'tax_amount'  => $taxAmount,
            ];
        }

        return $lines;
    }

    /**
     * Persist tax lines for an invoice.
     */
    public function applyTaxLines(Invoice $invoice, float $baseAmount, ?array $taxRateIds = null): float
    {
        $totalTax = 0;

        // If specific rates requested, use those; otherwise all active rates.
        $rates = $taxRateIds
            ? TaxRate::whereIn('id', $taxRateIds)->where('is_active', true)->get()
            : $this->getActiveRates();

        foreach ($rates as $rate) {
            $taxAmount = $rate->type === 'fixed'
                ? round((float) $rate->rate, 2)
                : round($baseAmount * ((float) $rate->rate / 100), 2);

            InvoiceTaxLine::create([
                'tenant_id'   => $invoice->tenant_id,
                'invoice_id'  => $invoice->id,
                'tax_rate_id' => $rate->id,
                'tax_name'    => $rate->name,
                'tax_code'    => $rate->code,
                'rate'        => $rate->rate,
                'base_amount' => $baseAmount,
                'tax_amount'  => $taxAmount,
            ]);

            $totalTax += $taxAmount;
        }

        return round($totalTax, 2);
    }

    /**
     * Recalculate an invoice's tax lines and total.
     */
    public function recalculateInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->taxLines()->delete();

            $baseAmount = (float) $invoice->subtotal > 0
                ? (float) $invoice->subtotal
                : (float) $invoice->amount;

            $tax = $this->applyTaxLines($invoice, $baseAmount);

            $invoice->update([
                'tax'   => $tax,
                'total' => round($baseAmount + $tax, 2),
            ]);

            return $invoice->fresh();
        });
    }
}
