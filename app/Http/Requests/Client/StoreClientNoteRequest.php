<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled at controller/policy level
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'string', Rule::in(['general', 'call', 'meeting', 'support'])],
            'priority' => ['required', 'string', Rule::in(['low', 'normal', 'high'])],
            'is_pinned' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Note content is required',
            'note.max' => 'Note cannot exceed 5000 characters',
            'type.in' => 'Invalid note type',
            'priority.in' => 'Invalid priority level',
        ];
    }
}
