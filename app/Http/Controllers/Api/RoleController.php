<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    // GET /api/roles
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 25);

        $roles = Role::orderBy('name')->paginate($perPage);

        return response()->json($roles);
    }

    // POST /api/roles
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('roles')->where('is_deleted', 0)],
        ]);

        $role = Role::create([
            'name'        => $validated['name'],
            'created_by'  => auth()->id() ?? 1,
            'modified_by' => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'Jabatan berhasil ditambahkan.',
            'data'    => $role,
        ], 201);
    }

    // GET /api/roles/{role}
    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $role]);
    }

    // PUT /api/roles/{role}
    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('roles')->where('is_deleted', 0)->ignore($role->id),
            ],
        ]);

        $role->update([
            'name'        => $validated['name'],
            'modified_by' => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'Jabatan berhasil diperbarui.',
            'data'    => $role->fresh(),
        ]);
    }

    // DELETE /api/roles/{role}
    public function destroy(Role $role): JsonResponse
    {
        $role->delete();

        return response()->json(['message' => 'Jabatan berhasil dihapus.']);
    }
}
