<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::In(['installation', 'repair', 'relocation', 'maintenance', 'survey'])],
            'priority' => ['required', 'string', Rule::In(['low', 'normal', 'high', 'urgent'])],
            'description' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Work order type is required',
            'type.in' => 'Invalid work order type',
            'priority.required' => 'Priority is required',
            'priority.in' => 'Invalid priority level',
            'description.required' => 'Description is required',
            'description.max' => 'Description cannot exceed 2000 characters',
        ];
    }
}
