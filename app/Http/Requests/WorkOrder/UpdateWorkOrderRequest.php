<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::In(['installation', 'repair', 'relocation', 'maintenance', 'survey'])],
            'priority' => ['sometimes', 'string', Rule::In(['low', 'normal', 'high', 'urgent'])],
            'description' => ['sometimes', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'status' => ['sometimes', 'string', Rule::In(['scheduled', 'dispatched', 'in_progress', 'completed', 'cancelled'])],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Invalid work order type',
            'priority.in' => 'Invalid priority level',
            'status.in' => 'Invalid work order status',
        ];
    }
}
