<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\ClientTag;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

class ClientTagService
{
    public function getAllTags(): array
    {
        return ClientTag::orderBy('name')->get()->toArray();
    }

    public function createTag(array $data): ClientTag
    {
        $tag = new ClientTag($data);
        $tag->tenant_id = Tenant::current()?->id;
        $tag->save();

        return $tag;
    }

    public function updateTag(ClientTag $tag, array $data): ClientTag
    {
        $tag->update($data);
        return $tag->fresh();
    }

    public function deleteTag(ClientTag $tag): void
    {
        $tag->delete();
    }

    public function assignToClient(Client $client, ClientTag $tag): void
    {
        $client->tags()->attach($tag->id, [
            'tenant_id' => $client->tenant_id,
            'assigned_by' => Auth::id(),
        ]);
    }

    public function removeFromClient(Client $client, ClientTag $tag): void
    {
        $client->tags()->detach($tag->id);
    }

    public function getClientTags(Client $client): array
    {
        return $client->tags()->get()->toArray();
    }
}
