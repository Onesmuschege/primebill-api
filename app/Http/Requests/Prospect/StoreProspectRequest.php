<?php

namespace App\Http\Requests\Prospect;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProspectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('create prospects');
    }

    public function rules(): array
    {
        return [
            'lead_id'                => ['nullable', 'exists:leads,id'],
            'first_name'             => ['required', 'string', 'max:50'],
            'last_name'              => ['required', 'string', 'max:50'],
            'email'                  => ['nullable', 'email:rfc'],
            'phone'                  => ['required', 'regex:/^(254|\+254|0)[1-9]\d{8}$/'],
            'alt_phone'              => ['nullable', 'regex:/^(254|\+254|0)[1-9]\d{8}$/'],
            'address'                => ['nullable', 'string', 'max:500'],
            'town'                   => ['nullable', 'string', 'max:50'],
            'county'                 => ['nullable', 'string', 'max:50'],
            'gps_lat'                => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng'                => ['nullable', 'numeric', 'between:-180,180'],
            'interested_package'     => ['nullable', 'string', 'max:100'],
            'installation_type'      => ['nullable', Rule::in(Prospect::INSTALLATION_TYPES)],
            'installation_feasible'  => ['nullable', 'boolean'],
            'feasibility_notes'      => ['nullable', 'string'],
            'installation_fee_quoted'=> ['nullable', 'numeric', 'min:0'],
            'pipeline_stage'         => ['sometimes', Rule::in(Prospect::PIPELINE_STAGES)],
            'notes'                  => ['nullable', 'string'],
            'assigned_to'            => ['nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone must be a valid Kenyan number (0/254/+254)',
        ];
    }
}
