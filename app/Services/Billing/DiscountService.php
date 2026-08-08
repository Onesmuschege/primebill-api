<?php

namespace App\Services\Billing;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\InvoiceDiscountLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DiscountService
{
    /**
     * Validate a coupon code and return the coupon if valid.
     */
    public function validateCoupon(string $code, int $clientId, float $subtotal): Coupon
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            throw new RuntimeException('Invalid coupon code.');
        }

        if (!$coupon->isActive()) {
            throw new RuntimeException('Coupon is not active or has expired.');
        }

        if (!$coupon->canBeUsedBy($clientId)) {
            throw new RuntimeException('Coupon usage limit reached for this client.');
        }

        if ((float) $coupon->min_subtotal > 0 && $subtotal < (float) $coupon->min_subtotal) {
            throw new RuntimeException('Subtotal does not meet the coupon minimum.');
        }

        return $coupon;
    }

    /**
     * Calculate the discount amount for a coupon against a subtotal.
     */
    public function calculateCouponDiscount(Coupon $coupon, float $subtotal): float
    {
        $discount = $coupon->type === 'percentage'
            ? $subtotal * ((float) $coupon->value / 100)
            : (float) $coupon->value;

        if ($coupon->max_discount !== null && $discount > (float) $coupon->max_discount) {
            $discount = (float) $coupon->max_discount;
        }

        return round(max(0, $discount), 2);
    }

    /**
     * Apply a coupon to an invoice, recording the discount line + redemption.
     */
    public function applyCoupon(Invoice $invoice, string $code, int $clientId): float
    {
        return DB::transaction(function () use ($invoice, $code, $clientId) {
            $subtotal = (float) $invoice->subtotal > 0
                ? (float) $invoice->subtotal
                : (float) $invoice->amount;

            $coupon = $this->validateCoupon($code, $clientId, $subtotal);
            $discountAmount = $this->calculateCouponDiscount($coupon, $subtotal);

            if ($discountAmount <= 0) {
                throw new RuntimeException('Coupon discount is zero.');
            }

            InvoiceDiscountLine::create([
                'tenant_id'      => $invoice->tenant_id,
                'invoice_id'     => $invoice->id,
                'coupon_id'      => $coupon->id,
                'discount_name'  => 'Coupon ' . $coupon->code,
                'type'           => $coupon->type,
                'value'          => $coupon->value,
                'base_amount'    => $subtotal,
                'discount_amount' => $discountAmount,
            ]);

            CouponRedemption::create([
                'tenant_id'       => $invoice->tenant_id,
                'coupon_id'       => $coupon->id,
                'client_id'       => $clientId,
                'invoice_id'      => $invoice->id,
                'discount_amount' => $discountAmount,
            ]);

            $coupon->increment('usage_count');

            // Update invoice discount + subtotal
            $newDiscount = round((float) $invoice->discount + $discountAmount, 2);
            $newSubtotal = round($subtotal - $discountAmount, 2);

            $invoice->update([
                'discount' => $newDiscount,
                'subtotal' => $newSubtotal,
            ]);

            return $discountAmount;
        });
    }

    /**
     * Apply a named discount to an invoice.
     */
    public function applyDiscount(Invoice $invoice, Discount $discount): float
    {
        return DB::transaction(function () use ($invoice, $discount) {
            if (!$discount->isActive()) {
                throw new RuntimeException('Discount is not active.');
            }

            $subtotal = (float) $invoice->subtotal > 0
                ? (float) $invoice->subtotal
                : (float) $invoice->amount;

            $discountAmount = $discount->type === 'percentage'
                ? $subtotal * ((float) $discount->value / 100)
                : (float) $discount->value;

            $discountAmount = round(max(0, $discountAmount), 2);

            if ($discountAmount <= 0) {
                throw new RuntimeException('Discount amount is zero.');
            }

            InvoiceDiscountLine::create([
                'tenant_id'       => $invoice->tenant_id,
                'invoice_id'      => $invoice->id,
                'discount_id'     => $discount->id,
                'discount_name'   => $discount->name,
                'type'            => $discount->type,
                'value'           => $discount->value,
                'base_amount'     => $subtotal,
                'discount_amount' => $discountAmount,
            ]);

            $newDiscount = round((float) $invoice->discount + $discountAmount, 2);
            $newSubtotal = round($subtotal - $discountAmount, 2);

            $invoice->update([
                'discount' => $newDiscount,
                'subtotal' => $newSubtotal,
            ]);

            return $discountAmount;
        });
    }
}
