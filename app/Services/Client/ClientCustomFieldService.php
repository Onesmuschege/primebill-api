<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\ClientCustomField;
use App\Models\ClientCustomFieldValue;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

class ClientCustomFieldService
{
    public function getAllFields(): array
    {
        return ClientCustomField::orderBy('sort_order')->get()->toArray();
    }

    public function createField(array $data): ClientCustomField
    {
        $field = new ClientCustomField($data);
        $field->tenant_id = Tenant::current()?->id;
        $field->save();

        return $field;
    }

    public function updateField(ClientCustomField $field, array $data): ClientCustomField
    {
        $field->update($data);
        return $field->fresh();
    }

    public function deleteField(ClientCustomField $field): void
    {
        $field->delete();
    }

    public function getClientFieldValues(Client $client): array
    {
        return $client->customFieldValues()
            ->with('field')
            ->get()
            ->mapWithKeys(fn($v) => [$v->field->name => $v->value])
            ->toArray();
    }

    public function updateClientFieldValues(Client $client, array $values): void
    {
        foreach ($values as $fieldName => $value) {
            $field = ClientCustomField::where('tenant_id', $client->tenant_id)
                ->where('name', $fieldName)
                ->first();

            if (!$field) continue;

            ClientCustomFieldValue::updateOrCreate(
                [
                    'tenant_id' => $client->tenant_id,
                    'client_id' => $client->id,
                    'client_custom_field_id' => $field->id,
                ],
                ['value' => $value]
            );
        }
    }
}
