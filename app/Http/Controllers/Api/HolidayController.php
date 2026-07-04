<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    /**
     * GET /api/holidays
     * List all holidays
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $startDate = $request->input('start_date')
            ? strtotime($request->input('start_date'))
            : strtotime('first day of january this year');
        $endDate = $request->input('end_date')
            ? strtotime($request->input('end_date').' 23:59:59')
            : strtotime('last day of december this year');

        // A holiday with projects_id = null is company-wide ("Semua Cabang") and
        // must be visible from every branch. The generic BranchScoped scope only
        // matches projects_id = active branch (dropping nulls), so bypass it and
        // apply "active branch OR global" ourselves. Super admin viewing all
        // branches (active = null) keeps seeing everything.
        $activeBranch = app('branch.context')->getActiveBranch();

        $holidays = Holiday::withoutBranchScope()
            ->with('project')
            ->where('date', '>=', $startDate)
            ->where('date', '<=', $endDate)
            ->when($activeBranch !== null, function ($query) use ($activeBranch) {
                $query->where(function ($q) use ($activeBranch) {
                    $q->where('projects_id', $activeBranch)->orWhereNull('projects_id');
                });
            })
            ->orderBy('date', 'asc')
            ->get();

        $data = $holidays->map(function ($holiday) {
            return [
                'id' => $holiday->id,
                'date' => $holiday->date,
                'name' => $holiday->name,
                'description' => $holiday->description,
                'branch_id' => $holiday->projects_id,
                'branch_name' => $holiday->project?->name ?? 'Semua Cabang',
                'created_at' => $holiday->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * POST /api/holidays
     * Create new holiday
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $currentUser = $request->user();
        $date = strtotime($request->input('date'));

        // Check if already exists for this date and branch. Bypass the branch
        // scope so the check works for global (null) holidays too.
        $existing = Holiday::withoutBranchScope()
            ->where('date', '>=', strtotime(date('Y-m-d', $date)))
            ->where('date', '<', strtotime(date('Y-m-d', $date).' +1 day'))
            ->where(function ($query) use ($request) {
                if ($request->input('branch_id')) {
                    $query->where('projects_id', $request->input('branch_id'));
                } else {
                    $query->whereNull('projects_id');
                }
            })
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Hari libur untuk tanggal ini sudah ada',
            ], 422);
        }

        // Persist the chosen branch_id verbatim — a null means "Semua Cabang".
        // withoutEvents skips BranchScoped's creating hook, which would otherwise
        // overwrite an explicit null with the creator's active branch.
        $holiday = Holiday::withoutEvents(fn () => Holiday::create([
            'date' => $date,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'projects_id' => $request->input('branch_id'),
            'created_by' => $currentUser->id,
        ]));

        return response()->json([
            'message' => 'Hari libur berhasil ditambahkan',
            'data' => $holiday,
        ], 201);
    }

    /**
     * PUT /api/holidays/{id}
     * Update holiday
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        // Bypass the branch scope so global (null) holidays are editable too.
        $holiday = Holiday::withoutBranchScope()->findOrFail($id);
        $currentUser = $request->user();

        $holiday->update([
            'date' => strtotime($request->input('date')),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'projects_id' => $request->input('branch_id'),
            'modified_by' => $currentUser->id,
        ]);

        return response()->json([
            'message' => 'Hari libur berhasil diupdate',
            'data' => $holiday,
        ]);
    }

    /**
     * DELETE /api/holidays/{id}
     * Delete holiday (soft delete)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // Bypass the branch scope so global (null) holidays are deletable too.
        $holiday = Holiday::withoutBranchScope()->findOrFail($id);
        $currentUser = $request->user();

        $holiday->update([
            'is_deleted' => 1,
            'modified_by' => $currentUser->id,
        ]);

        return response()->json([
            'message' => 'Hari libur berhasil dihapus',
        ]);
    }
}
