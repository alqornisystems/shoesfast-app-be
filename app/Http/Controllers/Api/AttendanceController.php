<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAbsence;
use App\Models\DailyNote;
use App\Models\Holiday;
use App\Models\Project;
use App\Services\NotifikasiTugas;
use App\Support\FotoBase64;
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
                'message' => 'Anda tidak dapat melakukan absensi. Anda memiliki izin yang disetujui untuk hari ini.',
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
                'message' => 'Anda sudah absen masuk hari ini',
            ], 422);
        }

        // Get branch location
        $project = Project::find($user->projects_id ?? 1);

        if (! $project || ! $project->latitude || ! $project->longitude) {
            return response()->json([
                'message' => 'Lokasi cabang belum diatur. Hubungi admin.',
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

        // Radius dibaca dari satu konstanta yang juga dikirim ke klien lewat login dan
        // /auth/me. Angka yang ditulis dua kali akan berselisih suatu hari, dan selisihnya
        // baru ketahuan saat ada karyawan gagal absen di lapangan.
        if (! $isWfa && $distance > AuthController::ATTENDANCE_RADIUS_METERS) {
            return response()->json([
                'message' => 'Anda berada di luar radius absensi (1 km dari kantor)',
                'distance' => round($distance, 2),
                'max_distance' => AuthController::ATTENDANCE_RADIUS_METERS,
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
                'message' => 'Anda tidak dapat melakukan absensi. Anda memiliki izin yang disetujui untuk hari ini.',
            ], 422);
        }

        // Check if clocked in today
        $clockIn = Attendance::where('users_id', $user->id)
            ->where('type', 0)
            ->where('clock', '>=', $today)
            ->where('clock', '<', $tomorrow)
            ->first();

        if (! $clockIn) {
            return response()->json([
                'message' => 'Anda belum absen masuk hari ini',
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
                'message' => 'Anda sudah absen pulang hari ini',
            ], 422);
        }

        // Catatan harian menahan absen pulang: hari tanpa catatan sama sekali ditolak, dan
        // catatan yang masih terbuka juga ditolak.
        //
        // API lama melewati aturan ini lewat parameter `is_web` — pembebasan yang bisa dipakai
        // siapa saja hanya dengan menambahkan satu parameter, jadi tidak ditiru. Penggantinya
        // adalah jabatan: aturan ini memang untuk staf lapangan (Teknisi & Kurir), sedangkan
        // Admin dan Admin Super mengabsenkan orang dari layar admin dan tidak boleh terkunci
        // oleh catatan milik orang lain. Jabatan kantor lain (HRD, Finance, Sosmed, Crm) tidak
        // pernah terkena aturan ini di aplikasi lama, jadi tetap tidak terkena.
        $jabatan = strtolower(trim((string) ($user->role?->name ?? '')));

        if (in_array($jabatan, ['teknisi', 'kurir'], true)) {
            $catatan = DailyNote::where('users_id', $user->id)
                ->where('date', '>=', $today)
                ->where('date', '<', $tomorrow)
                ->get();

            if ($catatan->isEmpty()) {
                return response()->json([
                    'message' => 'Anda belum membuat catatan harian hari ini. Buat catatan dulu sebelum absen pulang.',
                ], 422);
            }

            $belumSelesai = $catatan->where('status', '!=', 1)->count();

            if ($belumSelesai > 0) {
                return response()->json([
                    'message' => "Masih ada {$belumSelesai} catatan harian hari ini yang belum diselesaikan. Selesaikan dulu sebelum absen pulang.",
                ], 422);
            }
        }

        // Get branch location
        $project = Project::find($user->projects_id ?? 1);

        if (! $project || ! $project->latitude || ! $project->longitude) {
            return response()->json([
                'message' => 'Lokasi cabang belum diatur. Hubungi admin.',
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

        // Radius dibaca dari satu konstanta yang juga dikirim ke klien lewat login dan
        // /auth/me. Angka yang ditulis dua kali akan berselisih suatu hari, dan selisihnya
        // baru ketahuan saat ada karyawan gagal absen di lapangan.
        if (! $isWfa && $distance > AuthController::ATTENDANCE_RADIUS_METERS) {
            return response()->json([
                'message' => 'Anda berada di luar radius absensi (1 km dari kantor)',
                'distance' => round($distance, 2),
                'max_distance' => AuthController::ATTENDANCE_RADIUS_METERS,
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
            ? strtotime($request->input('end_date').' 23:59:59')
            : strtotime('today 23:59:59');

        // Determine if this is for report (all users) or personal (logged in user only)
        // Convert string "true"/"false" to boolean
        $reportModeParam = $request->input('report_mode', false);
        $isReportMode = $reportModeParam === 'true' || $reportModeParam === true || $reportModeParam === 1 || $reportModeParam === '1';

        // Mode laporan melewati branch scope dan menampilkan absensi SEMUA karyawan. Sebelumnya
        // parameter ini tidak dijaga sama sekali: siapa pun cukup menambahkan ?report_mode=true
        // untuk membaca absensi seluruh perusahaan. Hanya admin yang boleh.
        if ($isReportMode && ! $this->isAdmin($request)) {
            $isReportMode = false;
        }

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

        // Filter by specific user_id if provided (for admin viewing specific user).
        // Untuk non-admin parameter ini diabaikan — kalau dihormati, absensi orang lain bisa
        // dibaca hanya dengan menebak sebuah id.
        if ($this->isAdmin($request) && $request->has('user_id') && $request->input('user_id')) {
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
            return date('Y-m-d', $item->clock).'-'.$item->users_id;
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
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        $query = AttendanceAbsence::with('user');

        // Penyaring rentang tanggal. Sebelumnya endpoint ini mengabaikan keduanya dan
        // selalu mengirim SELURUH riwayat pengajuan; klien yang cuma butuh tahun berjalan
        // tetap harus mengunduh semuanya. Dicocokkan ke date_start karena itulah tanggal
        // yang dipakai pengguna saat mencari izinnya.
        if ($request->filled('start_date')) {
            $query->where('date_start', '>=', strtotime($request->input('start_date')));
        }

        if ($request->filled('end_date')) {
            $query->where('date_start', '<=', strtotime($request->input('end_date').' 23:59:59'));
        }

        // Hanya admin yang melihat pengajuan semua orang; sisanya miliknya sendiri.
        //
        // Penjaga sebelumnya tidak pernah bekerja seperti yang tertulis: `$user->is_super_admin`
        // bukan atribut apa pun di model User (selalu null), dan `$user->role` adalah objek Role,
        // bukan string — jadi `!== 'HRD'` selalu benar. Sekarang dibandingkan lewat isAdmin(),
        // yang membaca nama jabatan.
        if (! $this->isAdmin($request)) {
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
                'photo' => $this->fotoIzinUrl($absence->photo),
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
     * GET /api/attendances/daily-status
     *
     * Status per tanggal untuk kalender presensi: hadir, izin, libur, akhir pekan, atau
     * alpa. Bahannya sudah tersebar di tiga endpoint (`/attendances`, `/absences`,
     * `/holidays`) dan aplikasi tidak bisa menyatukannya sendiri tanpa tiga permintaan
     * plus aturan bisnis yang seharusnya tidak tinggal di klien.
     *
     * `is_late` SENGAJA tidak dikirim: jam masuk resmi tidak ada di database mana pun,
     * jadi "terlambat" belum punya definisi. API lama menghitungnya dengan "jam > 8 ATAU
     * menit >= 40", yang menandai absen 07:45 sebagai terlambat. Menampilkan angka yang
     * salah dengan percaya diri lebih buruk daripada tidak menampilkannya.
     */
    public function dailyStatus(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $start = strtotime(date('Y-m-d', $request->filled('start_date')
            ? strtotime($request->input('start_date'))
            : strtotime('first day of this month')));
        $end = strtotime(date('Y-m-d', $request->filled('end_date')
            ? strtotime($request->input('end_date'))
            : time()));

        // Rentang dibatasi supaya satu permintaan tidak pernah membangun ribuan baris di
        // memori — kalender hanya menampilkan sebulan.
        if ($end < $start) {
            return response()->json(['message' => 'Tanggal akhir mendahului tanggal mulai.'], 422);
        }

        if (($end - $start) / 86400 > 366) {
            return response()->json(['message' => 'Rentang maksimal satu tahun.'], 422);
        }

        // Tiga sumber ditarik sekali, lalu dipetakan per tanggal. Query di dalam loop
        // tanggal akan menghasilkan puluhan query untuk satu kalender.
        $hadir = [];
        foreach (Attendance::where('users_id', $user->id)
            ->where('type', 0)
            ->where('clock', '>=', $start)
            ->where('clock', '<', $end + 86400)
            ->get() as $baris) {
            $hadir[date('Y-m-d', $baris->clock)] = $baris->clock;
        }

        $izin = [];
        foreach (AttendanceAbsence::where('users_id', $user->id)
            ->where('is_approval', 1)
            ->where('is_deleted', 0)
            ->where('date_start', '<=', $end + 86399)
            ->where('date_end', '>=', $start)
            ->get() as $baris) {
            for ($t = $baris->date_start; $t <= $baris->date_end; $t += 86400) {
                $izin[date('Y-m-d', $t)] = $this->getAbsenceTypeLabel((int) $baris->type);
            }
        }

        // Libur milik cabang aktif DAN libur seluruh perusahaan (projects_id null) —
        // aturan yang sama dengan HolidayController::index.
        $cabang = app('branch.context')->getActiveBranch();
        $libur = [];
        foreach (Holiday::withoutBranchScope()
            ->where('date', '>=', $start)
            ->where('date', '<=', $end + 86399)
            ->when($cabang !== null, function ($q) use ($cabang) {
                $q->where(function ($sub) use ($cabang) {
                    $sub->where('projects_id', $cabang)->orWhereNull('projects_id');
                });
            })
            ->get() as $baris) {
            $libur[date('Y-m-d', $baris->date)] = $baris->name;
        }

        $hariIni = strtotime('today');
        $data = [];

        for ($t = $start; $t <= $end; $t += 86400) {
            $tanggal = date('Y-m-d', $t);

            // Urutan pemeriksaan menentukan artinya. Hadir menang atas segalanya: orang
            // yang tetap masuk di hari libur memang hadir, dan menandainya "libur" akan
            // menghapus kerjanya dari catatan.
            if (isset($hadir[$tanggal])) {
                $data[] = ['date' => $tanggal, 'status' => 'present', 'clock_in' => $hadir[$tanggal]];
            } elseif (isset($izin[$tanggal])) {
                $data[] = ['date' => $tanggal, 'status' => 'absence', 'absence_type' => $izin[$tanggal]];
            } elseif (isset($libur[$tanggal])) {
                $data[] = ['date' => $tanggal, 'status' => 'holiday', 'name' => $libur[$tanggal]];
            } elseif (date('N', $t) === '7') {
                $data[] = ['date' => $tanggal, 'status' => 'weekend'];
            } elseif ($t > $hariIni) {
                // Hari yang belum terjadi bukan alpa. Tanpa cabang ini, membuka kalender
                // tanggal 3 akan mengecat sisa bulan merah semua.
                $data[] = ['date' => $tanggal, 'status' => 'upcoming'];
            } else {
                $data[] = ['date' => $tanggal, 'status' => 'absent'];
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Simpan data URL gambar ke storage publik, kembalikan jalur relatifnya.
     *
     * @throws \Exception kalau yang dikirim bukan data URL gambar
     */
    private function simpanFotoIzin(string $dataUrl): string
    {
        if (! preg_match('/^data:image\/(\w+);base64,/', $dataUrl)) {
            throw new \Exception('Format gambar tidak dikenali');
        }

        return FotoBase64::simpan($dataUrl, 'absences');
    }

    /**
     * Kolom `photo` menyimpan tiga bentuk sekaligus karena umur data yang berbeda:
     * URL absolut (data lama), jalur relatif 'absences/x.jpg' (unggahan baru), dan nama
     * berkas telanjang 'x.jpg' (unggahan sebelum perbaikan). Ketiganya dinormalkan di
     * sini supaya klien tidak perlu menebak folder — tebakan itulah yang sekarang
     * ditambal di aplikasi mobile.
     */
    private function fotoIzinUrl(?string $photo): ?string
    {
        if (empty($photo)) {
            return null;
        }

        if (filter_var($photo, FILTER_VALIDATE_URL)) {
            return $photo;
        }

        return asset('storage/'.(str_contains($photo, '/') ? $photo : 'absences/'.$photo));
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
        $dateEnd = strtotime($request->input('date_end').' 23:59:59');
        $totalDays = floor(($dateEnd - $dateStart) / 86400) + 1;

        // Foto izin datang sebagai data URL (`data:image/png;base64,...`), sama seperti
        // unggahan lain di API ini.
        //
        // Dua cacat diperbaiki di sini sekaligus. Pertama, awalan data URL dulu tidak
        // dibuang sebelum base64_decode — dan awalan itu sendiri berisi karakter yang sah
        // di base64, jadi tidak ada galat yang terlihat: berkasnya tersimpan, isinya saja
        // yang rusak dan tidak pernah bisa dibuka. Kedua, semuanya disimpan berakhiran
        // .jpg apa pun jenis aslinya.
        //
        // Yang DISIMPAN sekarang jalur relatifnya ('absences/xxx.png'), bukan nama berkas
        // telanjang, supaya sama dengan orders_items dan klien tidak perlu menebak folder.
        $photoPath = null;
        if (! empty($request->input('photo'))) {
            try {
                $photoPath = $this->simpanFotoIzin($request->input('photo'));
            } catch (\Exception $e) {
                \Log::error('Gagal menyimpan foto izin: '.$e->getMessage());
                // Pengajuan izin tetap dibuat tanpa foto — menolak seluruh pengajuan
                // hanya karena lampirannya gagal akan menahan orang yang sedang sakit.
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
            'photo' => $photoPath,
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

        app(NotifikasiTugas::class)->izinDiputuskan($absence->users_id, $absence->id, true);

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

        app(NotifikasiTugas::class)->izinDiputuskan($absence->users_id, $absence->id, false);

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
        return match ($type) {
            0 => 'Sakit',
            1 => 'Izin',
            2 => 'Cuti',
            default => 'Unknown',
        };
    }

    private function getApprovalLabel(int $status): string
    {
        return match ($status) {
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
