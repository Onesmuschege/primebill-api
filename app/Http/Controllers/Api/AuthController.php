<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Traits\ApiResponse;
use App\Services\LoginHistoryService;
use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LoginHistoryService $loginHistoryService,
        private MfaService $mfaService
    ) {}

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            $this->loginHistoryService->recordFailedLogin($request->email, $request, 'Invalid credentials');
            return $this->error('Invalid credentials', null, 401);
        }

        $user  = Auth::user();

        // If the user has MFA enabled, do NOT issue a full session token yet.
        // Return a short-lived `mfa_token` that the frontend uses to call
        // POST /api/mfa/challenge with the TOTP/backup code.
        if ($this->mfaService->isEnabled($user)) {
            $mfaToken = $user->createToken('mfa-pending', ['mfa-pending'], now()->addMinutes(5))->plainTextToken;

            // Record that login reached the MFA challenge stage (not yet a
            // successful login — success is recorded after code verification).
            SystemLog::create([
                'user_id'    => $user->id,
                'action'     => 'login.mfa_challenge',
                'model'      => 'User',
                'model_id'   => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $this->success([
                'mfa_required' => true,
                'mfa_token' => $mfaToken,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                ],
            ], 'MFA verification required');
        }

        $token = $user->createToken('auth_token', ['admin'])->plainTextToken;

        // Record login history
        $this->loginHistoryService->recordLogin($user, $request);

        SystemLog::create([
            'user_id'    => $user->id,
            'action'     => 'login',
            'model'      => 'User',
            'model_id'   => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                // Cross-tenant PrimeBill-operator flag — separate from
                // roles/permissions, which are always tenant-scoped. The
                // frontend uses this alone to decide whether to show the
                // platform-admin dashboard route.
                'is_platform_admin' => (bool) $user->is_platform_admin,
            ],
            'token' => $token,
        ], 'Login successful');
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_platform_admin' => (bool) $user->is_platform_admin,
        ]);
    }

    public function logout(Request $request)
    {
        $this->loginHistoryService->recordLogout($request->user(), $request);

        SystemLog::create([
            'user_id'    => $request->user()->id,
            'action'     => 'logout',
            'model'      => 'User',
            'model_id'   => $request->user()->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect', null, 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return $this->success(null, 'Password changed successfully');
    }
}
