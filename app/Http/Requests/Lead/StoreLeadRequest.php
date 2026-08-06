<?php

namespace App\Http\Requests\Lead;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('create leads');
    }

    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:50'],
            'last_name'    => ['required', 'string', 'max:50'],
            'email'        => ['nullable', 'email:rfc'],
            'phone'        => ['required', 'regex:/^(254|\+254|0)[1-9]\d{8}$/'],
            'alt_phone'    => ['nullable', 'regex:/^(254|\+254|0)[1-9]\d{8}$/'],
            'address'      => ['nullable', 'string', 'max:500'],
            'town'         => ['nullable', 'string', 'max:50'],
            'county'       => ['nullable', 'string', 'max:50'],
            'gps_lat'      => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng'      => ['nullable', 'numeric', 'between:-180,180'],
            'source'       => ['required', Rule::in(Lead::SOURCES)],
            'interest_plan'=> ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
            'assigned_to'  => ['nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone must be a valid Kenyan number (0/254/+254)',
        ];
    }
}
