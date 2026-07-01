<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    // GET /api/admin/roles
    public function index()
    {
        $roles = Role::with('permissions')
            ->where('name', '!=', 'super_admin')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $roles,
        ]);
    }

    // GET /api/admin/permissions
    public function permissions()
    {
        $permissions = Permission::all()->groupBy(function ($p) {
            return explode(' ', $p->name)[0]; // Group by first word (create, view, edit, delete)
        });

        return response()->json([
            'success' => true,
            'data'    => $permissions,
        ]);
    }

    // POST /api/admin/roles
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|unique:roles,name',
            'permissions' => 'array',
        ]);

        $role = Role::create(['name' => $request->name, 'guard_name' => 'web']);
        
        if ($request->filled('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data'    => $role->load('permissions'),
        ], 201);
    }

    // PUT /api/admin/roles/{id}
    public function update(Request $request, Role $role)
    {
        if ($role->name === 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify super_admin role',
            ], 422);
        }

        $request->validate(['permissions' => 'array']);

        if ($request->filled('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data'    => $role->load('permissions'),
        ]);
    }
}
