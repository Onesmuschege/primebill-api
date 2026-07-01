<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RadiusSettingsController extends Controller
{
    protected SettingsService $settingsService;
    protected RadiusAdapterInterface $radiusAdapter;

    public function __construct(SettingsService $settingsService, RadiusAdapterInterface $radiusAdapter)
    {
        $this->settingsService = $settingsService;
        $this->radiusAdapter = $radiusAdapter;
    }

    // GET /api/radius-settings
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'radius_driver' => config('network.radius_driver', 'mock'),
                'radius_connection' => config('network.radius_connection', 'radius'),
                'available_drivers' => ['mock', 'freeradius'],
            ],
        ]);
    }

    // POST /api/radius-settings/test
    public function test()
    {
        try {
            $result = $this->radiusAdapter->syncUsers();
            
            return response()->json([
                'success' => $result,
                'message' => $result 
                    ? 'RADIUS connection successful' 
                    : 'RADIUS sync failed — check connection and tables',
            ]);
        } catch (\Exception $e) {
            Log::error('RADIUS test failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'RADIUS test failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
