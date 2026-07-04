<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAbsence;
use App\Models\DailyNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyNoteController extends Controller
{
    /**
     * GET /api/daily-notes
     * List daily notes with filters
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $startDate = $request->input('start_date')
            ? strtotime($request->input('start_date'))
            : strtotime('-30 days');
        $endDate = $request->input('end_date')
            ? strtotime($request->input('end_date').' 23:59:59')
            : time();

        $query = DailyNote::with(['user', 'project'])
            ->where('is_deleted', 0)
            ->where('date', '>=', $startDate)
            ->where('date', '<=', $endDate);

        // Always show current user's data by default
        // Only show other user's data if explicitly requested via user_id parameter
        if ($request->has('user_id')) {
            $query->where('users_id', $request->input('user_id'));
        } else {
            $query->where('users_id', $user->id);
        }

        $notes = $query->orderBy('date', 'desc')->get();

        $data = $notes->map(function ($note) {
            return [
                'id' => $note->id,
                'user_id' => $note->users_id,
                'user_name' => $note->user?->name,
                'branch_name' => $note->project?->name,
                'date' => $note->date,
                'note' => $note->title,
                'activities' => $note->description,
                'status' => $note->status ?? 0,
                'created_at' => $note->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * GET /api/daily-notes/today
     * Get today's note for current user
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');

        $note = DailyNote::with(['user', 'project'])
            ->where('users_id', $user->id)
            ->where('date', '>=', $today)
            ->where('date', '<', $tomorrow)
            ->where('is_deleted', 0)
            ->first();

        if (! $note) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $note->id,
                'user_id' => $note->users_id,
                'user_name' => $note->user?->name,
                'branch_name' => $note->project?->name,
                'date' => $note->date,
                'note' => $note->title,
                'activities' => $note->description,
                'status' => $note->status ?? 0,
                'created_at' => $note->created_at,
            ],
        ]);
    }

    /**
     * POST /api/daily-notes
     * Create daily note
     * Admin/HRD can create for other users by specifying user_id
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'note' => ['required', 'string'],
            'activities' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'], // For admin/HRD only
        ]);

        $currentUser = $request->user();
        $date = strtotime($request->input('date'));

        // Determine target user
        $targetUserId = $request->input('user_id');

        // Authorization: Only admin/HRD can create for others
        if ($targetUserId && $targetUserId != $currentUser->id) {
            $isSuperAdmin = $currentUser->projects_id === null;
            if (! $isSuperAdmin && ! in_array($currentUser->role, ['Admin', 'HRD'])) {
                return response()->json([
                    'message' => 'Anda tidak memiliki akses untuk membuat catatan untuk user lain',
                ], 403);
            }

            // Verify target user exists and get their branch
            $targetUser = \App\Models\User::find($targetUserId);
            if (! $targetUser) {
                return response()->json([
                    'message' => 'User tidak ditemukan',
                ], 404);
            }
        } else {
            $targetUserId = $currentUser->id;
            $targetUser = $currentUser;
        }

        // Check if date has approved absence
        $hasApprovedAbsence = AttendanceAbsence::where('users_id', $targetUserId)
            ->where('is_approval', 1) // approved
            ->where('date_start', '<=', $date)
            ->where('date_end', '>=', $date)
            ->where('is_deleted', 0)
            ->exists();

        if ($hasApprovedAbsence) {
            return response()->json([
                'message' => 'Tidak dapat membuat catatan harian. Anda memiliki izin yang disetujui untuk tanggal ini.',
            ], 422);
        }

        // Check if already exists for this date
        $existing = DailyNote::where('users_id', $targetUserId)
            ->where('date', '>=', strtotime(date('Y-m-d', $date)))
            ->where('date', '<', strtotime(date('Y-m-d', $date).' +1 day'))
            ->where('is_deleted', 0)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Catatan harian untuk tanggal ini sudah ada',
            ], 422);
        }

        $note = DailyNote::create([
            'projects_id' => $targetUser->projects_id ?? 1,
            'users_id' => $targetUserId,
            'date' => $date,
            'title' => $request->input('note'),
            'description' => $request->input('activities'),
            'status' => 0,
            'created_by' => $currentUser->id,
        ]);

        return response()->json([
            'message' => 'Catatan harian berhasil dibuat',
            'data' => $note,
        ], 201);
    }

    /**
     * GET /api/daily-notes/available-users
     * Get all available users for daily notes (for admin/HRD)
     * Only returns employees (users with roles_id), not vendors/partnerships
     */
    public function availableUsers(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        // Only admin/HRD can get users list
        $isSuperAdmin = $currentUser->projects_id === null;
        if (! $isSuperAdmin && ! in_array($currentUser->role, ['Admin', 'HRD'])) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses',
            ], 403);
        }

        $query = \App\Models\User::query()
            ->with('role')
            ->where('is_deleted', 0)
            ->whereNotNull('roles_id') // Only employees with roles (not vendors)
            ->orderBy('name');

        // Branch users only see their branch
        $isSuperAdmin = $currentUser->projects_id === null;
        if (! $isSuperAdmin && $currentUser->projects_id) {
            $query->where('projects_id', $currentUser->projects_id);
        }

        $users = $query->get(['id', 'name', 'phone', 'roles_id']);

        return response()->json([
            'data' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role?->name ?? 'N/A',
                ];
            }),
        ]);
    }

    /**
     * GET /api/daily-notes/search-users
     * Search users for daily notes (for admin/HRD)
     * Only returns employees (users with roles_id), not vendors/partnerships
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $currentUser = $request->user();

        // Only admin/HRD can search users
        $isSuperAdmin = $currentUser->projects_id === null;
        if (! $isSuperAdmin && ! in_array($currentUser->role, ['Admin', 'HRD'])) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses',
            ], 403);
        }

        $search = $request->input('search', '');

        $query = \App\Models\User::query()
            ->with('role')
            ->where('is_deleted', 0)
            ->whereNotNull('roles_id') // Only employees with roles (not vendors)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('name');

        // Branch users only see their branch
        $isSuperAdmin = $currentUser->projects_id === null;
        if (! $isSuperAdmin && $currentUser->projects_id) {
            $query->where('projects_id', $currentUser->projects_id);
        }

        $users = $query->limit(20)->get(['id', 'name', 'phone', 'roles_id']);

        return response()->json([
            'data' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role?->name ?? 'N/A',
                ];
            }),
        ]);
    }

    /**
     * GET /api/daily-notes/{id}
     * Show single note
     */
    public function show(int $id): JsonResponse
    {
        $note = DailyNote::with(['user', 'project'])
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $note->id,
                'user_id' => $note->users_id,
                'user_name' => $note->user?->name,
                'branch_name' => $note->project?->name,
                'date' => $note->date,
                'note' => $note->title,
                'activities' => $note->description,
                'status' => $note->status ?? 0,
                'created_at' => $note->created_at,
            ],
        ]);
    }

    /**
     * PUT /api/daily-notes/{id}
     * Update daily note
     * Only owner can update their own note
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'note' => ['required', 'string'],
            'activities' => ['nullable', 'string'],
        ]);

        $currentUser = $request->user();
        $note = DailyNote::where('id', $id)
            ->where('is_deleted', 0)
            ->firstOrFail();

        // Check ownership - only owner can update
        if ($note->users_id !== $currentUser->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah catatan ini',
            ], 403);
        }

        $note->update([
            'title' => $request->input('note'),
            'description' => $request->input('activities'),
            'modified_by' => $currentUser->id,
        ]);

        return response()->json([
            'message' => 'Catatan harian berhasil diupdate',
            'data' => $note,
        ]);
    }

    /**
     * PUT /api/daily-notes/{id}/toggle-status
     * Toggle done status of daily note
     * Only owner can toggle their own note
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'integer', 'in:0,1'],
        ]);

        $currentUser = $request->user();
        $note = DailyNote::where('id', $id)
            ->where('is_deleted', 0)
            ->firstOrFail();

        // Check ownership - only owner can toggle
        if ($note->users_id !== $currentUser->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah status catatan ini',
            ], 403);
        }

        $note->update([
            'status' => $request->input('status'),
            'modified_by' => $currentUser->id,
        ]);

        return response()->json([
            'message' => 'Status catatan berhasil diubah',
            'data' => $note,
        ]);
    }

    /**
     * DELETE /api/daily-notes/{id}
     * Delete daily note (soft delete)
     * Only owner can delete their own note
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $note = DailyNote::where('id', $id)
            ->where('is_deleted', 0)
            ->firstOrFail();

        // Check ownership - only owner can delete
        if ($note->users_id !== $currentUser->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk menghapus catatan ini',
            ], 403);
        }

        $note->update([
            'is_deleted' => 1,
            'modified_by' => $currentUser->id,
        ]);

        return response()->json([
            'message' => 'Catatan harian berhasil dihapus',
        ]);
    }
}
