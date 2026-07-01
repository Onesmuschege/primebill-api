<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

class BulkGenerateVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create vouchers');
    }

    public function rules(): array
    {
        return [
            'plan_id'     => 'required|exists:plans,id',
            'quantity'    => 'required|integer|min:1|max:1000',
            'expiry_days' => 'nullable|integer|min:1|max:365',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.max' => 'Maximum 1000 vouchers can be generated at once',
        ];
    }
}
