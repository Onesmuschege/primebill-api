<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Models\Client;
use App\Services\Billing\BalanceService;
use App\Services\Client\ClientService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    protected ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    // GET /api/clients
    public function index(Request $request)
    {
        $clients = $this->clientService->getAllClients($request);

        return response()->json([
            'success' => true,
            'data'    => $clients,
        ]);
    }

    // POST /api/clients
    public function store(StoreClientRequest $request)
    {
        $client = $this->clientService->createClient(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Client created successfully',
            'data'    => $client,
        ], 201);
    }

    // GET /api/clients/{id}
    public function show(Client $client)
    {
        $client->load(['accounts.plan', 'invoices', 'payments', 'tickets']);

        return response()->json([
            'success' => true,
            'data'    => $client,
        ]);
    }

    // PUT /api/clients/{id}
    public function update(UpdateClientRequest $request, Client $client)
    {
        $client = $this->clientService->updateClient(
            $client,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Client updated successfully',
            'data'    => $client,
        ]);
    }

    // DELETE /api/clients/{id}
    public function destroy(Request $request, Client $client)
    {
        $this->clientService->deleteClient($client, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Client deleted successfully',
        ]);
    }

    // GET /api/clients/{id}/accounts
    public function accounts(Client $client)
    {
        return response()->json([
            'success' => true,
            'data'    => $client->accounts()->with('plan')->get(),
        ]);
    }

    // GET /api/clients/{id}/invoices
    public function invoices(Client $client)
    {
        return response()->json([
            'success' => true,
            'data'    => $client->invoices()->orderBy('created_at', 'desc')->get(),
        ]);
    }

    // GET /api/clients/{id}/payments
    public function payments(Client $client)
    {
        return response()->json([
            'success' => true,
            'data'    => $client->payments()->orderBy('created_at', 'desc')->get(),
        ]);
    }

    // GET /api/clients/{id}/tickets
    public function tickets(Client $client)
    {
        return response()->json([
            'success' => true,
            'data'    => $client->tickets()->orderBy('created_at', 'desc')->get(),
        ]);
    }

    // GET /api/clients/{id}/balance
    public function balance(Client $client, BalanceService $balanceService)
    {
        return response()->json([
            'success' => true,
            'data'    => $balanceService->getClientBalance($client->id),
        ]);
    }

    // POST /api/clients/{id}/suspend
    public function suspend(Request $request, Client $client)
    {
        $client = $this->clientService->suspendClient($client, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Client suspended successfully',
            'data'    => $client,
        ]);
    }

    // POST /api/clients/{id}/activate
    public function activate(Request $request, Client $client)
    {
        $client = $this->clientService->activateClient($client, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Client activated successfully',
            'data'    => $client,
        ]);
    }
}
