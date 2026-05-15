<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\SystemLog;
use Illuminate\Http\Request;

class ClientService
{
    /**
     * Get filtered and paginated list of system clients.
     */
    public function getAllClients(Request $request)
    {
        $query = Client::query();

        // Filter by status — filled() ignores empty string, has() does not
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by town
        if ($request->filled('town')) {
            $query->where('town', $request->town);
        }

        // Search by name, phone or email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')
                     ->paginate($request->per_page ?? 15);
    }

    /**
     * Create client and record activity system logs.
     */
    public function createClient(array $data, $userId)
    {
        $data['created_by'] = $userId;
        $client = Client::create($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'created client',
            'model'      => 'Client',
            'model_id'   => $client->id,
            'new_values' => $data,
        ]);

        return $client;
    }

    /**
     * Update client attributes and record modification logs.
     */
    public function updateClient(Client $client, array $data, $userId)
    {
        $oldValues = $client->toArray();
        $client->update($data);

        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'updated client',
            'model'      => 'Client',
            'model_id'   => $client->id,
            'old_values' => $oldValues,
            'new_values' => $data,
        ]);

        return $client;
    }

    /**
     * Suspend client profile and cascade status to accounts.
     */
    public function suspendClient(Client $client, $userId)
    {
        $client->update(['status' => 'suspended']);

        $client->accounts()->where('status', 'active')
               ->update(['status' => 'suspended']);

        SystemLog::create([
            'user_id'  => $userId,
            'action'   => 'suspended client',
            'model'    => 'Client',
            'model_id' => $client->id,
        ]);

        return $client;
    }

    /**
     * Activate client profile and cascade status to accounts.
     */
    public function activateClient(Client $client, $userId)
    {
        $client->update(['status' => 'active']);

        $client->accounts()->where('status', 'suspended')
               ->update(['status' => 'active']);

        SystemLog::create([
            'user_id'  => $userId,
            'action'   => 'activated client',
            'model'    => 'Client',
            'model_id' => $client->id,
        ]);

        return $client;
    }

    /**
     * Delete client record and capture snapshot details to logs.
     */
    public function deleteClient(Client $client, $userId)
    {
        SystemLog::create([
            'user_id'    => $userId,
            'action'     => 'deleted client',
            'model'      => 'Client',
            'model_id'   => $client->id,
            'old_values' => $client->toArray(),
        ]);

        $client->delete();
    }
}