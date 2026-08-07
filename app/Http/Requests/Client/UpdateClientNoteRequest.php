<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled at controller/policy level
    }

    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', Rule::In(['general', 'call', 'meeting', 'support'])],
            'priority' => ['sometimes', 'string', Rule::In(['low', 'normal', 'high'])],
            'is_pinned' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.max' => 'Note cannot exceed 5000 characters',
            'type.in' => 'Invalid note type',
            'priority.in' => 'Invalid priority level',
        ];
    }
}
