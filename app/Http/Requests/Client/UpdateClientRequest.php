<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('edit clients');
    }

    public function rules(): array
    {
        $clientId = $this->route('client')?->id ?? $this->route('client');

        return [
            'first_name'   => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z\s]+$/'],
            'last_name'    => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z\s]+$/'],
            'email'        => ['required', 'email:rfc,dns', Rule::unique('clients', 'email')->ignore($clientId)],
            'phone'        => ['required', 'regex:/^(254|\+254|0)[1-9]\d{8}$/', Rule::unique('clients', 'phone')->ignore($clientId)],
            'id_number'    => ['required', 'regex:/^\d{1,8}$/', Rule::unique('clients', 'id_number')->ignore($clientId)],
            'address'      => ['required', 'string', 'min:5', 'max:500'],
            'city'         => ['required', 'string', 'max:50'],
            'account_type' => ['required', 'in:residential,commercial,corporate'],
            'plan_id'      => ['required', 'exists:plans,id'],
            'status'       => ['sometimes', 'in:active,suspended,inactive'],
        ];
    }
}
