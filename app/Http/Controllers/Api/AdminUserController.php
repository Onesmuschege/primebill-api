<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminUserController extends Controller
{
    // GET /api/admin/users
    public function index(Request $request)
    {
        $users = User::with('roles', 'permissions')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    // POST /api/admin/users
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'role_id'  => 'required|exists:roles,id',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => bcrypt($request->password),
        ]);

        $role = Role::findOrFail($request->role_id);
        $user->assignRole($role);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data'    => $user->load('roles'),
        ], 201);
    }

    // PUT /api/admin/users/{id}
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role_id' => 'sometimes|exists:roles,id',
        ]);

        $user->update($request->only('name', 'email'));

        if ($request->filled('role_id')) {
            $role = Role::findOrFail($request->role_id);
            $user->syncRoles([$role]);
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data'    => $user->load('roles'),
        ]);
    }

    // DELETE /api/admin/users/{id}
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}
