<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return $this->error('Invalid credentials', null, 401);
        }

        $user  = Auth::user();
        $token = $user->createToken('auth_token', ['admin'])->plainTextToken;

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
