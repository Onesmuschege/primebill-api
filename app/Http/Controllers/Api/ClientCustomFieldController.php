<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientCustomField;
use App\Services\Client\ClientCustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientCustomFieldController extends Controller
{
    public function __construct(private ClientCustomFieldService $customFields) {}

    public function index(): JsonResponse
    {
        $fields = $this->customFields->getAllFields();

        return response()->json([
            'success' => true,
            'data' => $fields,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url'])],
            'options' => ['nullable', 'array'],
            'is_required' => ['boolean'],
            'is_visible_on_portal' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $field = $this->customFields->createField($validated);

        return response()->json([
            'success' => true,
            'message' => 'Custom field created successfully',
            'data' => $field,
        ], 201);
    }

    public function show(ClientCustomField $field): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $field,
        ]);
    }

    public function update(Request $request, ClientCustomField $field): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'label' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url'])],
            'options' => ['nullable', 'array'],
            'is_required' => ['boolean'],
            'is_visible_on_portal' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $field = $this->customFields->updateField($field, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Custom field updated successfully',
            'data' => $field,
        ]);
    }

    public function destroy(ClientCustomField $field): JsonResponse
    {
        $this->customFields->deleteField($field);

        return response()->json([
            'success' => true,
            'message' => 'Custom field deleted successfully',
        ]);
    }

    public function clientValues(Client $client): JsonResponse
    {
        $values = $this->customFields->getClientFieldValues($client);

        return response()->json([
            'success' => true,
            'data' => $values,
        ]);
    }

    public function updateClientValues(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'values' => ['required', 'array'],
        ]);

        $this->customFields->updateClientFieldValues($client, $validated['values']);

        return response()->json([
            'success' => true,
            'message' => 'Custom field values updated successfully',
        ]);
    }
}
