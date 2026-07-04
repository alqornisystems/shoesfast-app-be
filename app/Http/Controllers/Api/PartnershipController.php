<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnershipController extends Controller
{
    /**
     * GET /api/partnerships
     * Get all partnerships with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $search = $request->input('search');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 15);

        $query = Partnership::with('project');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $partnerships = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $partnerships->map(function ($partnership) {
                return [
                    'id' => $partnership->id,
                    'name' => $partnership->name,
                    'phone' => $partnership->phone,
                    'address' => $partnership->address,
                    'account_number' => $partnership->account_number,
                    'branch_name' => $partnership->project?->name ?? '-',
                    'created_at' => $partnership->created_at,
                ];
            }),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/partnerships
     * Create new partnership
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:25'],
            'address' => ['nullable', 'string'],
            'account_number' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $partnership = Partnership::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'address' => $validated['address'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'created_by' => $user->id,
            'modified_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Mitra kerja berhasil ditambahkan',
            'data' => $partnership,
        ], 201);
    }

    /**
     * GET /api/partnerships/{id}
     * Get single partnership
     */
    public function show(int $id): JsonResponse
    {
        $partnership = Partnership::with('project')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $partnership->id,
                'name' => $partnership->name,
                'phone' => $partnership->phone,
                'address' => $partnership->address,
                'account_number' => $partnership->account_number,
                'projects_id' => $partnership->projects_id,
                'branch_name' => $partnership->project?->name ?? '-',
                'created_at' => $partnership->created_at,
            ],
        ]);
    }

    /**
     * PUT /api/partnerships/{id}
     * Update partnership
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $partnership = Partnership::findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:25'],
            'address' => ['nullable', 'string'],
            'account_number' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $partnership->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'address' => $validated['address'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'modified_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Mitra kerja berhasil diperbarui',
            'data' => $partnership,
        ]);
    }

    /**
     * DELETE /api/partnerships/{id}
     * Soft delete partnership
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $partnership = Partnership::withoutGlobalScope('notDeleted')->findOrFail($id);

        // Check if partnership has active treatments
        $activeTreatments = $partnership->treatments()
            ->whereIn('status', [0, 1]) // Waiting or In Progress
            ->count();

        if ($activeTreatments > 0) {
            return response()->json([
                'message' => "Tidak dapat menghapus mitra kerja. Masih ada {$activeTreatments} treatment aktif.",
            ], 422);
        }

        $user = $request->user();

        $partnership->update([
            'is_deleted' => 1,
            'modified_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Mitra kerja berhasil dihapus',
        ]);
    }
}
