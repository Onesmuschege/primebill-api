<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminUserController extends Controller
{
    // GET /api/admin/users
    public function index()
    {
        // User deliberately has no BelongsToTenant global scope (login must look
        // up by email before a tenant is known), so every admin-users query must
        // be tenant-scoped explicitly — otherwise a tenant admin would see every
        // user across ALL tenants. Resolve the tenant from the authenticated user.
        $tenantId = auth('sanctum')->user()->tenant_id;

        $users = User::with('roles:id,name')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    // POST /api/admin/users
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|string|exists:roles,name',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'tenant_id' => auth('sanctum')->user()->tenant_id,
        ]);

        $user->assignRole($request->role);

        return response()->json([
            'success' => true,
            'message' => 'Admin user created',
            'data'    => $user->load('roles:id,name'),
        ], 201);
    }

    // PUT /api/admin/users/{user}/role
    public function assignRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|string|exists:roles,name']);

        // Defense-in-depth: User route binding is not tenant-scoped, so guard the
        // target record explicitly to block cross-tenant role assignment.
        if ((int) $user->tenant_id !== (int) auth('sanctum')->user()->tenant_id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $user->syncRoles([$request->role]);

        return response()->json([
            'success' => true,
            'message' => "Role updated to {$request->role}",
            'data'    => $user->load('roles:id,name'),
        ]);
    }

    // DELETE /api/admin/users/{user}
    public function destroy(User $user)
    {
        // Defense-in-depth: guard against deleting another tenant's user.
        if ((int) $user->tenant_id !== (int) auth('sanctum')->user()->tenant_id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if ($user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete super admin.',
            ], 403);
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'User deleted']);
    }

    // GET /api/admin/roles
    public function roles()
    {
        $roles = Role::with('permissions:id,name')->get();

        return response()->json(['success' => true, 'data' => $roles]);
    }

    // GET /api/admin/permissions
    public function permissions()
    {
        $permissions = Permission::orderBy('name')->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    // PUT /api/admin/roles/{role}/permissions
    public function syncPermissions(Request $request, Role $role)
    {
        $request->validate(['permissions' => 'required|array', 'permissions.*' => 'string|exists:permissions,name']);

        $role->syncPermissions($request->permissions);

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated',
            'data'    => $role->load('permissions:id,name'),
        ]);
    }
}