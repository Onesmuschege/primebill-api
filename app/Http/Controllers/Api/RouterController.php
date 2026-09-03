<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Router\StoreRouterRequest;
use App\Http\Requests\Router\UpdateRouterRequest;
use App\Models\Router;
use App\Services\Network\RouterHealthService;
use App\Services\Network\RouterService;
use Illuminate\Http\Request;

class RouterController extends Controller
{
    protected RouterService $routerService;

    public function __construct(RouterService $routerService)
    {
        $this->routerService = $routerService;
    }

    // GET /api/routers
    public function index()
    {
        $routers = $this->routerService->getAllRouters();

        return response()->json([
            'success' => true,
            'data'    => $routers,
        ]);
    }

    // POST /api/routers
    public function store(StoreRouterRequest $request)
    {
        $router = $this->routerService->createRouter(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Router created successfully',
            'data'    => $router,
        ], 201);
    }

    // GET /api/routers/{id}
    public function show(Router $router)
    {
        return response()->json([
            'success' => true,
            'data'    => $router,
        ]);
    }

    // PUT /api/routers/{id}
    public function update(UpdateRouterRequest $request, Router $router)
    {
        $router = $this->routerService->updateRouter(
            $router,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Router updated successfully',
            'data'    => $router,
        ]);
    }

    // DELETE /api/routers/{id}
    public function destroy(Request $request, Router $router)
    {
        $this->routerService->deleteRouter($router, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Router deleted successfully',
        ]);
    }

    // POST /api/routers/{id}/test-connection
    public function testConnection(Router $router)
    {
        $connected = $this->routerService->testConnection($router);

        return response()->json([
            'success' => true,
            'data'    => [
                'connected' => $connected,
                'status'    => $connected ? 'online' : 'offline',
            ],
        ]);
    }

    // GET /api/routers/{id}/resources
    public function resources(Router $router)
    {
        $resources = $this->routerService->getRouterResources($router);

        return response()->json([
            'success' => true,
            'data'    => $resources,
        ]);
    }

    // GET /api/routers/{id}/sessions
    public function sessions(Router $router)
    {
        $sessions = $this->routerService->getActiveSessions($router);

        return response()->json([
            'success' => true,
            'data'    => $sessions,
        ]);
    }

    // GET /api/routers/{id}/health — live reachability probe (Sections 43/44).
    public function health(Router $router, RouterHealthService $health)
    {
        return response()->json([
            'success' => true,
            'data'    => $health->check($router),
        ]);
    }

    // GET /api/routers/health — probe every router in the tenant.
    public function healthAll(RouterHealthService $health)
    {
        return response()->json([
            'success' => true,
            'data'    => $health->checkAll(),
        ]);
    }
}
