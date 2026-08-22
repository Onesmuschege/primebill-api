<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Services\Sms\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    // GET /api/settings
    public function index()
    {
        $settings = $this->settingsService->getAllGrouped();

        return response()->json([
            'success' => true,
            'data'    => $settings,
        ]);
    }

    // PUT /api/settings
    public function update(Request $request)
    {
        $request->validate([
            'settings' => 'required|array',
        ]);

        $this->settingsService->bulkUpdate($request->settings);

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
        ]);
    }

    // POST /api/settings/test-sms
    public function testSms(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $smsService = new SmsService();
        $sent = $smsService->send(
            $request->phone,
            'This is a test SMS from ' . config('brand.brand') . '. Your SMS gateway is working correctly.'
        );

        return response()->json([
            'success' => $sent,
            'message' => $sent ? 'Test SMS sent successfully' : 'Failed to send test SMS',
        ]);
    }

    // POST /api/settings/upload-logo
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $path = $request->file('logo')->store('public/logo');
        $url  = Storage::url($path);

        $this->settingsService->set('company_logo', $url);

        return response()->json([
            'success' => true,
            'message' => 'Logo uploaded successfully',
            'data'    => ['url' => $url],
        ]);
    }
}
