<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('create payments');
    }

    public function rules(): array
    {
        return [
            'client_id'  => 'required|exists:clients,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount'     => 'required|numeric|min:1',
            'method'     => 'required|in:mpesa,cash,bank,other',
            'reference'  => 'nullable|string',
            'mpesa_code' => 'nullable|string',
            'notes'      => 'nullable|string',
        ];
    }
}
