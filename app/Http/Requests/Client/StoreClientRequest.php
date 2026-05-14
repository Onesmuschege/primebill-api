<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

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
            'email'        => ['required', 'email:rfc,dns', 'unique:clients,email'],
            'phone'        => ['required', 'regex:/^(254|\+254|0)[1-9]\d{8}$/', 'unique:clients,phone'],
            'id_number'    => ['required', 'regex:/^\d{1,8}$/', 'unique:clients,id_number'],
            'address'      => ['required', 'string', 'min:5', 'max:500'],
            'city'         => ['required', 'string', 'max:50'],
            'account_type' => ['required', 'in:residential,commercial,corporate'],
            'plan_id'      => ['required', 'exists:plans,id'],
            'status'       => ['sometimes', 'in:active,suspended,inactive'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'     => 'Phone must be a valid Kenyan number (0/254/+254)',
            'id_number.regex' => 'ID number must be 1-8 digits',
            'email.unique'    => 'A client with this email already exists',
        ];
    }
}
