<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a dunning escalation step (the collections ladder).
 *
 * The dunning ladder is tenant-scoped (BelongsToTenant) and the action
 * enum mirrors the schema in 2026_08_08_000007_create_collections_tables.php
 * (as widened by 2026_08_30_000004_add_whatsapp_to_dunning_step_action_enum.php)
 * so a step can never be persisted with an unknown action.
 */
class StoreDunningStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) optional($this->user())->can('manage dunning');
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'sequence'       => ['required', 'integer', 'min:1'],
            'action'         => ['required', Rule::in(['email', 'sms', 'whatsapp', 'call', 'suspend', 'escalate'])],
            'days_after_due' => ['required', 'integer', 'min:0'],
            'template'       => ['nullable', 'string'],
            'is_active'      => ['boolean'],
        ];
    }
}