<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\ClientNote;
use Illuminate\Support\Facades\Auth;

class ClientNoteService
{
    public function getNotes(Client $client, array $filters = []): array
    {
        $query = $client->notes()->with('creator');

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['pinned_only'])) {
            $query->where('is_pinned', true);
        }

        return $query->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function createNote(Client $client, array $data): ClientNote
    {
        $note = new ClientNote($data);
        $note->tenant_id = $client->tenant_id;
        $note->client_id = $client->id;
        $note->created_by = Auth::id();
        $note->save();

        return $note->load('creator');
    }

    public function updateNote(ClientNote $note, array $data): ClientNote
    {
        $note->update($data);
        return $note->fresh('creator');
    }

    public function deleteNote(ClientNote $note): void
    {
        $note->delete();
    }

    public function togglePin(ClientNote $note): ClientNote
    {
        $note->update([
            'is_pinned' => !$note->is_pinned,
            'pinned_at' => !$note->is_pinned ? now() : null,
        ]);

        return $note->fresh();
    }
}
