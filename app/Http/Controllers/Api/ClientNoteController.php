<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientNoteRequest;
use App\Http\Requests\Client\UpdateClientNoteRequest;
use App\Models\Client;
use App\Services\Client\ClientNoteService;
use Illuminate\Http\JsonResponse;

class ClientNoteController extends Controller
{
    public function __construct(private ClientNoteService $notes) {}

    public function index(Client $client): JsonResponse
    {
        $notes = $this->notes->getNotes($client, request()->all());

        return response()->json([
            'success' => true,
            'data' => $notes,
        ]);
    }

    public function store(StoreClientNoteRequest $request, Client $client): JsonResponse
    {
        $note = $this->notes->createNote($client, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Note created successfully',
            'data' => $note,
        ], 201);
    }

    public function show(Client $client, $noteId): JsonResponse
    {
        $note = $client->notes()->with('creator')->findOrFail($noteId);

        return response()->json([
            'success' => true,
            'data' => $note,
        ]);
    }

    public function update(UpdateClientNoteRequest $request, Client $client, $noteId): JsonResponse
    {
        $note = $client->notes()->findOrFail($noteId);
        $note = $this->notes->updateNote($note, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Note updated successfully',
            'data' => $note,
        ]);
    }

    public function destroy(Client $client, $noteId): JsonResponse
    {
        $note = $client->notes()->findOrFail($noteId);
        $this->notes->deleteNote($note);

        return response()->json([
            'success' => true,
            'message' => 'Note deleted successfully',
        ]);
    }

    public function togglePin(Client $client, $noteId): JsonResponse
    {
        $note = $client->notes()->findOrFail($noteId);
        $note = $this->notes->togglePin($note);

        return response()->json([
            'success' => true,
            'message' => $note->is_pinned ? 'Note pinned' : 'Note unpinned',
            'data' => $note,
        ]);
    }
}
