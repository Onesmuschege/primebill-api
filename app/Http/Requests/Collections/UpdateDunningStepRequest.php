<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a dunning escalation step.
 *
 * All fields are optional (PATCH semantics); when supplied they are
 * validated against the same constraints as creation.
 */
class UpdateDunningStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) optional($this->user())->can('manage dunning');
    }

    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'sequence'       => ['sometimes', 'required', 'integer', 'min:1'],
            'action'         => ['sometimes', 'required', Rule::in(['email', 'sms', 'whatsapp', 'call', 'suspend', 'escalate'])],
            'days_after_due' => ['sometimes', 'required', 'integer', 'min:0'],
            'template'       => ['nullable', 'string'],
            'is_active'      => ['boolean'],
        ];
    }
}