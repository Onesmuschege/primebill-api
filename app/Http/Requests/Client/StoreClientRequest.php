<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('create clients');
    }

    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z\s]+$/'],
            'last_name'    => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z\s]+$/'],
            'email'        => ['nullable', 'email:rfc', Rule::unique('clients', 'email')],
            'phone'        => ['required', 'regex:/^(254|\+254|0)[1-9]\d{8}$/', Rule::unique('clients', 'phone')],
            'id_number'    => ['nullable', 'regex:/^\d{1,8}$/', Rule::unique('clients', 'id_number')],
            'address'      => ['nullable', 'string', 'min:5', 'max:500'],
            'county'       => ['nullable', 'string', 'max:50'],
            'town'         => ['nullable', 'string', 'max:50'],
            'status'       => ['sometimes', 'in:active,suspended,inactive,disabled'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'     => 'Phone must be a valid Kenyan number (0/254/+254)',
            'id_number.regex' => 'ID number must be 1-8 digits',
            'email.unique'    => 'A client with this email already exists',
            'phone.unique'    => 'A client with this phone number already exists',
        ];
    }
}
