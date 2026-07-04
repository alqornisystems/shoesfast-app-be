<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAbsence;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * GET /api/attendances/today
     * Get today's attendance status for current user
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');

        $clockIn = Attendance::where('users_id', $user->id)
            ->where('type', 0)
            ->where('clock', '>=', $today)
            ->where('clock', '<', $tomorrow)
            ->first();

        $clockOut = Attendance::where('users_id', $user->id)
            ->where('type', 1)
            ->where('clock', '>=', $today)
            ->where('clock', '<', $tomorrow)
            ->first();

        return response()->json([
            'clock_in' => $clockIn ? [
                'id' => $clockIn->id,
                'time' => $clockIn->clock,
                'is_wfa' => $clockIn->is_wfa,
            ] : null,
            'clock_out' => $clockOut ? [
                'id' => $clockOut->id,
                'time' => $clockOut->clock,
                'is_wfa' => $clockOut->is_wfa,
            ] : null,
        ]);
    }

    /**
     * POST /api/attendances/clock-in
     * Clock in
     */
    public function clockIn(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_wfa' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');

        // Check if user has approved absence for today
        $hasApprovedAbsence = AttendanceAbsence::where('users_id', $user->id)
            ->where('is_approval', 1) // approved
            ->where('date_start', '<=', $today)
            ->where('date_end', '>=', $today)
            ->where('is_deleted', 0)
            ->exists();

        if ($hasApprovedAbsence) {
            return response()->json([
                'message' => 'Anda tidak dapat melakukan absensi. Anda memiliki izin yang disetujui untuk hari ini.'
            ], 422);
        }

        // Check if already clocked in today
        $existing = Attendance::where('users_id', $user->id)
            ->where('type', 0)
            ->where('clock', '>=', $today)
            ->where('clock', '<', $tomorrow)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Anda sudah absen masuk hari ini'
            ], 422);
        }

        // Get branch location
        $project = Project::find($user->projects_id ?? 1);

        if (!$project || !$project->latitude || !$project->longitude) {
            return response()->json([
                'message' => 'Lokasi cabang belum diatur. Hubungi admin.'
            ], 422);
        }

        $userLat = $request->input('latitude');
        $userLng = $request->input('longitude');
        $isWfa = $request->input('is_wfa', 0);

        // Calculate distance using Haversine formula
        $distance = $this->calculateDistance(
            $project->latitude,
            $project->longitude,
            $userLat,
            $userLng
        );

        // Validate radius (1000 meters = 1 km) - only if not WFA
        if (!$isWfa && $distance > 1000) {
            return response()->json([
                'message' => 'Anda berada di luar radius absensi (1 km dari kantor)',
                'distance' => round($distance, 2),
                'max_distance' => 1000,
            ], 422);
        }

        $attendance = Attendance::create([
            'projects_id' => $user->projects_id ?? 1,
            'users_id' => $user->id,
            'clock' => time(),
            'type' => 0, // clock in
            'latitude' => $userLat,
            'longitude' => $userLng,
            'distance' => round($distance, 2),
            'is_wfa' => $isWfa,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Berhasil absen masuk',
            'data' => $attendance,
            'distance' => round($distance, 2),
        ], 201);
    }

    /**
     * POST /api/attendances/clock-out
     * Clock out
     */
    public function clockOut(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_wfa' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');

        // Check if user has approved absence for today
        $hasApprovedAbsence = AttendanceAbsence::where('users_id', $user->id)
            ->where('is_approval', 1) // approved
            ->where('date_start', '<=', $today)
            ->where('date_end', '>=', $today)
            ->where('is_deleted', 0)
            ->exists();

        if ($hasApprovedAbsence) {
            return response()->json([
                'message' => 'Anda tidak dapat melakukan absensi. Anda memiliki izin yang disetujui untuk hari ini.'
            ], 422);
        }

        // Check if clocked in today
        $clockIn = Attendance::where('users_id', $user->id)
            ->where('type', 0)
            ->where('clock', '>=', $today)
            ->where('clock', '<', $tomorrow)
            ->first();

        if (!$clockIn) {
            return response()->json([
                'message' => 'Anda belum absen masuk hari ini'
            ], 422);
        }

        // Check if already clocked out
        $existing = Attendance::where('users_id', $user->id)
            ->where('type', 1)
            ->where('clock', '>=', $today)
            ->where('clock', '<', $tomorrow)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Anda sudah absen pulang hari ini'
            ], 422);
        }

        // Get branch location
        $project = Project::find($user->projects_id ?? 1);

        if (!$project || !$project->latitude || !$project->longitude) {
            return response()->json([
                'message' => 'Lokasi cabang belum diatur. Hubungi admin.'
            ], 422);
        }

        $userLat = $request->input('latitude');
        $userLng = $request->input('longitude');
        $isWfa = $request->input('is_wfa', 0);

        // Calculate distance using Haversine formula
        $distance = $this->calculateDistance(
            $project->latitude,
            $project->longitude,
            $userLat,
            $userLng
        );

        // Validate radius (1000 meters = 1 km) - only if not WFA
        if (!$isWfa && $distance > 1000) {
            return response()->json([
                'message' => 'Anda berada di luar radius absensi (1 km dari kantor)',
                'distance' => round($distance, 2),
                'max_distance' => 1000,
            ], 422);
        }

        $attendance = Attendance::create([
            'projects_id' => $user->projects_id ?? 1,
            'users_id' => $user->id,
            'clock' => time(),
            'type' => 1, // clock out
            'latitude' => $userLat,
            'longitude' => $userLng,
            'distance' => round($distance, 2),
            'is_wfa' => $isWfa,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Berhasil absen pulang',
            'data' => $attendance,
            'distance' => round($distance, 2),
        ], 201);
    }

    /**
     * GET /api/attendances
     * Get attendance history
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'report_mode' => ['nullable', 'in:true,false,1,0'], // Accept string or int boolean
        ]);

        $user = $request->user();
        $startDate = $request->input('start_date')
            ? strtotime($request->input('start_date'))
            : strtotime('-7 days');
        $endDate = $request->input('end_date')
            ? strtotime($request->input('end_date') . ' 23:59:59')
            : strtotime('today 23:59:59');

        // Determine if this is for report (all users) or personal (logged in user only)
        // Convert string "true"/"false" to boolean
        $reportModeParam = $request->input('report_mode', false);
        $isReportMode = $reportModeParam === 'true' || $reportModeParam === true || $reportModeParam === 1 || $reportModeParam === '1';

        // Build query
        if ($isReportMode) {
            // Report mode: bypass branch scope, tampilkan semua user
            $query = Attendance::withoutGlobalScope('branch')
                ->where('clock', '>=', $startDate)
                ->where('clock', '<=', $endDate);
        } else {
            // Personal mode: hanya user yang login
            $query = Attendance::where('users_id', $user->id)
                ->where('clock', '>=', $startDate)
                ->where('clock', '<=', $endDate);
        }

        // Filter by specific user_id if provided (for admin viewing specific user)
        if ($request->has('user_id') && $request->input('user_id')) {
            $query->where('users_id', $request->input('user_id'));
        }

        $attendances = $query->orderBy('clock', 'desc')->get();

        // Load users manually to avoid scope issues
        $userIds = $attendances->pluck('users_id')->unique()->toArray();
        $users = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $userIds)
            ->where('is_deleted', 0)
            ->get()
            ->keyBy('id');

        // Group by date and user
        $grouped = $attendances->groupBy(function ($item) {
            return date('Y-m-d', $item->clock) . '-' . $item->users_id;
        })->map(function ($group) use ($users) {
            $clockIn = $group->where('type', 0)->first();
            $clockOut = $group->where('type', 1)->first();
            $userId = $group->first()->users_id;
            $user = $users->get($userId);

            return [
                'date' => date('Y-m-d', $group->first()->clock),
                'user_id' => $userId,
                'user_name' => $user ? $user->name : 'Unknown User',
                'clock_in' => $clockIn ? [
                    'time' => $clockIn->clock,
                    'is_wfa' => $clockIn->is_wfa,
                ] : null,
                'clock_out' => $clockOut ? [
                    'time' => $clockOut->clock,
                    'is_wfa' => $clockOut->is_wfa,
                ] : null,
                'duration' => $clockIn && $clockOut
                    ? $clockOut->clock - $clockIn->clock
                    : null,
            ];
        })->values();

        return response()->json([
            'data' => $grouped,
        ]);
    }

    /**
     * GET /api/absences
     * Get absence requests
     */
    public function absences(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AttendanceAbsence::with('user');

        // HRD/Admin can see all, others only see their own
        if (!$user->is_super_admin && $user->role !== 'HRD' && $user->role !== 'Admin') {
            $query->where('users_id', $user->id);
        }

        $absences = $query->orderBy('date_start', 'desc')->get();

        $data = $absences->map(function ($absence) {
            return [
                'id' => $absence->id,
                'user_id' => $absence->users_id,
                'user_name' => $absence->user?->name,
                'type' => $absence->type,
                'type_label' => $this->getAbsenceTypeLabel($absence->type),
                'date_start' => $absence->date_start,
                'date_end' => $absence->date_end,
                'total_days' => $absence->total_days,
                'note' => $absence->note,
                'photo' => $absence->photo,
                'is_approval' => $absence->is_approval,
                'approval_label' => $this->getApprovalLabel($absence->is_approval),
                'created_at' => $absence->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * POST /api/absences
     * Submit absence request
     */
    public function storeAbsence(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'integer', 'in:0,1,2'], // 0=sakit, 1=izin, 2=cuti
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
            'note' => ['required', 'string'],
            'photo' => ['nullable', 'string'], // base64
        ]);

        $user = $request->user();
        $dateStart = strtotime($request->input('date_start'));
        $dateEnd = strtotime($request->input('date_end') . ' 23:59:59');
        $totalDays = floor(($dateEnd - $dateStart) / 86400) + 1;

        // Handle photo upload if exists
        $photoFilename = null;
        if ($request->has('photo') && !empty($request->input('photo'))) {
            try {
                $base64Image = $request->input('photo');
                $imageData = base64_decode($base64Image);

                // Generate unique filename
                $photoFilename = 'absence_' . time() . '_' . uniqid() . '.jpg';

                // Save to storage/app/public/absences/
                \Storage::disk('public')->put('absences/' . $photoFilename, $imageData);
            } catch (\Exception $e) {
                \Log::error('Failed to save absence photo: ' . $e->getMessage());
                // Continue without photo if upload fails
            }
        }

        $absence = AttendanceAbsence::create([
            'projects_id' => $user->projects_id ?? 1,
            'users_id' => $user->id,
            'type' => $request->input('type'),
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'total_days' => $totalDays,
            'note' => $request->input('note'),
            'photo' => $photoFilename,
            'is_approval' => 0, // pending
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Pengajuan izin berhasil dibuat',
            'data' => $absence,
        ], 201);
    }

    /**
     * PUT /api/absences/{id}/approve
     * Approve absence request
     */
    public function approveAbsence(Request $request, int $id): JsonResponse
    {
        $absence = AttendanceAbsence::findOrFail($id);

        $absence->update([
            'is_approval' => 1, // approved
            'modified_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Pengajuan izin berhasil disetujui',
            'data' => $absence,
        ]);
    }

    /**
     * PUT /api/absences/{id}/reject
     * Reject absence request
     */
    public function rejectAbsence(Request $request, int $id): JsonResponse
    {
        $absence = AttendanceAbsence::findOrFail($id);

        $absence->update([
            'is_approval' => 2, // rejected
            'modified_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Pengajuan izin ditolak',
            'data' => $absence,
        ]);
    }

    /**
     * DELETE /api/absences/{id}
     * Delete absence request
     */
    public function deleteAbsence(int $id): JsonResponse
    {
        $absence = AttendanceAbsence::findOrFail($id);

        $absence->update(['is_deleted' => 1]);

        return response()->json([
            'message' => 'Pengajuan izin berhasil dihapus',
        ]);
    }

    // Helpers
    private function getAbsenceTypeLabel(int $type): string
    {
        return match($type) {
            0 => 'Sakit',
            1 => 'Izin',
            2 => 'Cuti',
            default => 'Unknown',
        };
    }

    private function getApprovalLabel(int $status): string
    {
        return match($status) {
            0 => 'Pending',
            1 => 'Disetujui',
            2 => 'Ditolak',
            default => 'Unknown',
        };
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in meters
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Earth radius in meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($latFrom) * cos($latTo) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c; // Distance in meters
    }
}
