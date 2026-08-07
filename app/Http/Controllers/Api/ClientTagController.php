<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTag;
use App\Services\Client\ClientTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientTagController extends Controller
{
    public function __construct(private ClientTagService $tags) {}

    public function index(): JsonResponse
    {
        $tags = $this->tags->getAllTags();

        return response()->json([
            'success' => true,
            'data' => $tags,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:7'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tag = $this->tags->createTag($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tag created successfully',
            'data' => $tag,
        ], 201);
    }

    public function show(ClientTag $tag): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $tag,
        ]);
    }

    public function update(Request $request, ClientTag $tag): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:7'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tag = $this->tags->updateTag($tag, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Tag updated successfully',
            'data' => $tag,
        ]);
    }

    public function destroy(ClientTag $tag): JsonResponse
    {
        $this->tags->deleteTag($tag);

        return response()->json([
            'success' => true,
            'message' => 'Tag deleted successfully',
        ]);
    }

    public function assignToClient(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'client_tag_id' => ['required', 'exists:client_tags,id'],
        ]);

        $tag = ClientTag::findOrFail($request->client_tag_id);
        $this->tags->assignToClient($client, $tag);

        return response()->json([
            'success' => true,
            'message' => 'Tag assigned to client',
        ]);
    }

    public function removeFromClient(Request $request, Client $client, ClientTag $tag): JsonResponse
    {
        $this->tags->removeFromClient($client, $tag);

        return response()->json([
            'success' => true,
            'message' => 'Tag removed from client',
        ]);
    }

    public function clientTags(Client $client): JsonResponse
    {
        $tags = $this->tags->getClientTags($client);

        return response()->json([
            'success' => true,
            'data' => $tags,
        ]);
    }
}
