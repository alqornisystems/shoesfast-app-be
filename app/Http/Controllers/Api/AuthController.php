<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Base64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Normalize phone number: remove leading 0 or 62
     */
    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D/', '', $phone); // Remove non-digits

        if (str_starts_with($normalized, '62')) {
            $normalized = substr($normalized, 2); // Remove 62
        } elseif (str_starts_with($normalized, '0')) {
            $normalized = substr($normalized, 1); // Remove 0
        }

        return $normalized;
    }

    /**
     * Radius absensi dalam meter. Sama untuk semua cabang — tabel `projects`
     * tidak punya kolom radius. Dikirim ke klien lewat login dan /auth/me
     * supaya aplikasi mobile tidak perlu menuliskannya ulang: klien yang lebih
     * ketat daripada server menolak orang yang sebenarnya sah, dan bedanya
     * tidak akan ketahuan sampai ada karyawan gagal absen di lapangan.
     */
    public const ATTENDANCE_RADIUS_METERS = 1000;

    /**
     * Bentuk pengguna yang dikirim ke klien. Satu tempat, dipakai login
     * maupun /auth/me — dua salinan yang berbeda isinya persis jenis
     * ketidakcocokan yang membuat sesi hasil pemulihan kehilangan foto dan
     * nomor telepon yang tadinya ada saat login.
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // Sudah ternormalisasi tanpa awalan 0/62 — bentuk yang sama dengan
            // yang diterima endpoint login.
            'phone' => $user->phone,
            'photo' => $this->photoUrl($user->photo),
            'role' => $user->role?->name,
            'projects_id' => $user->projects_id,
            'project_name' => $user->project?->name,
            'is_super_admin' => $user->projects_id === null,
        ];
    }

    /**
     * Data lama menyimpan URL absolut, unggahan baru menyimpan jalur relatif.
     * Aturannya sama dengan OrderController dan Api\Customer\CatalogController.
     */
    private function photoUrl(?string $photo): ?string
    {
        if (empty($photo)) {
            return null;
        }

        return filter_var($photo, FILTER_VALIDATE_URL) ? $photo : asset('storage/'.$photo);
    }

    // POST /api/auth/login
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember_me' => ['nullable', 'boolean'],
        ]);

        $normalizedPhone = $this->normalizePhone($request->phone);

        $user = User::with(['role', 'project'])
            ->where('phone', $normalizedPhone)
            ->where('is_deleted', 0)
            ->first();

        if (! $user || ! $this->checkPassword($request->password, $user->password)) {
            return response()->json([
                'message' => 'Nomor telepon atau PIN salah.',
            ], 401);
        }

        // Revoke previous tokens and issue a new expiring one
        $user->tokens()->delete();

        $remember = (bool) $request->input('remember_me');
        [$token, $expiresAt] = $this->issueToken($user, $remember);

        $branchContext = app('branch.context');

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->presentUser($user),
            'branch' => [
                'active_id' => $branchContext->getActiveBranch(),
                'active_name' => $branchContext->getActiveBranchName(),
                'can_switch' => $branchContext->isSuperAdmin(),
            ],
            'attendance' => [
                'radius_meters' => self::ATTENDANCE_RADIUS_METERS,
            ],
        ]);
    }

    // POST /api/auth/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        app('branch.context')->reset();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    // POST /api/auth/refresh
    // Tukar token yang masih valid dengan token baru ber-expiry segar.
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        // Pertahankan tipe sesi (remember atau tidak) dari token saat ini
        $remember = str_contains((string) ($current->name ?? ''), 'remember');

        // Cabut hanya token saat ini (device lain tetap login)
        $current->delete();

        [$token, $expiresAt] = $this->issueToken($user, $remember);

        return response()->json([
            'message' => 'Token diperbarui.',
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Terbitkan token akses ber-expiry.
     *
     * @return array{0: string, 1: \Illuminate\Support\Carbon}
     */
    private function issueToken(User $user, bool $remember): array
    {
        $ttl = $remember
            ? (int) config('sanctum.remember_token_ttl', 43200)
            : (int) config('sanctum.token_ttl', 1440);

        $expiresAt = now()->addMinutes($ttl);
        $tokenName = $remember ? 'web-admin-remember' : 'web-admin';

        $token = $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

        return [$token, $expiresAt];
    }

    // POST /api/auth/switch-branch
    public function switchBranch(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $branchContext = app('branch.context');

        if (! $branchContext->isSuperAdmin()) {
            return response()->json([
                'message' => 'Only super admin can switch branches.',
            ], 403);
        }

        $branchId = $request->input('branch_id');
        $branchContext->switchBranch($branchId);

        return response()->json([
            'message' => 'Branch switched successfully.',
            'branch' => [
                'active_id' => $branchContext->getActiveBranch(),
                'active_name' => $branchContext->getActiveBranchName(),
            ],
        ]);
    }

    // GET /api/auth/me
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'project']);
        $branchContext = app('branch.context');

        return response()->json([
            'user' => $this->presentUser($user),
            'branch' => [
                'active_id' => $branchContext->getActiveBranch(),
                'active_name' => $branchContext->getActiveBranchName(),
                'can_switch' => $branchContext->isSuperAdmin(),
            ],
            'attendance' => [
                'radius_meters' => self::ATTENDANCE_RADIUS_METERS,
            ],
        ]);
    }

    // PUT /api/auth/profile
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Nullable, bukan required: sebagian staf lapangan tidak punya email, dan
            // memaksanya membuat layar profil mereka tidak pernah bisa disimpan.
            'email' => ['nullable', 'email', 'max:50'],
            'photo' => ['nullable', 'string'],
        ]);

        // `phone` sengaja TIDAK ada di daftar. Nomor itu adalah identitas login; kalau
        // pemiliknya bisa menggantinya sendiri, salah ketik satu digit mengunci dirinya
        // keluar dan tidak ada yang bisa dilakukannya dari dalam aplikasi. Perubahan
        // nomor lewat admin (PUT /api/users/{id}), yang juga menjaga bentroknya.

        $foto = $user->photo;

        if (array_key_exists('photo', $validated)) {
            // null berarti hapus. Data URL disimpan ke disk lalu yang masuk kolom adalah
            // jalurnya — menulis base64 mentah ke kolom TEXT berarti MySQL memotongnya
            // diam-diam dan fotonya rusak tanpa satu pun galat.
            $foto = $validated['photo'] === null
                ? null
                : Base64Image::store($validated['photo'], 'users');
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? $user->email,
            'photo' => $foto,
            'modified_by' => $user->id,
        ]);

        // Bentuk yang sama persis dengan login dan /auth/me, jadi klien boleh memakainya
        // langsung tanpa memanggil /auth/me lagi. Sebelumnya balasan di sini punya
        // susunannya sendiri — dua bentuk untuk data yang sama adalah cara paling pasti
        // membuat sesi dan tampilan berselisih.
        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $this->presentUser($user->fresh()->load(['role', 'project'])),
        ]);
    }

    // PUT /api/auth/change-password
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        // Verify current password
        if (! $this->checkPassword($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Password lama tidak sesuai.',
            ], 422);
        }

        // Update to new password (bcrypt)
        $user->update([
            'password' => Hash::make($validated['new_password']),
            'modified_by' => $user->id,
        ]);

        // Revoke all tokens to force re-login
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan login kembali.',
        ]);
    }

    // POST /api/auth/device-token
    public function registerDeviceToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'in:android,ios'],
        ]);

        // users_id selalu dari token sesi, tidak pernah dari badan permintaan.
        //
        // Cocokkan berdasarkan token saja, tanpa scope `notDeleted`: kolom token
        // unique, jadi baris lama yang sudah dihapus lunak harus dihidupkan
        // kembali, bukan disisipkan lagi. Dan kalau token itu terdaftar atas
        // pengguna LAIN, kepemilikannya dipindahkan ke pengguna sekarang — FCM
        // memindahkan token ketika sebuah perangkat dipakai orang lain, dan
        // tanpa ini notifikasi tugas akan terus terkirim ke pemilik lama.
        DeviceToken::withoutGlobalScope('notDeleted')->updateOrCreate(
            ['token' => $validated['token']],
            [
                'users_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? null,
                'is_deleted' => 0,
            ],
        );

        return response()->json(['message' => 'Token perangkat tersimpan.']);
    }

    // DELETE /api/auth/device-token
    // Dipakai saat logout supaya perangkat yang dipinjamkan berhenti menerima
    // notifikasi.
    public function deleteDeviceToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        // Hanya token milik pengguna yang sedang login.
        DeviceToken::where('users_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->update(['is_deleted' => 1]);

        return response()->json(['message' => 'Token perangkat dihapus.']);
    }

    /**
     * Support both bcrypt (new) and SHA1 (legacy) passwords.
     */
    private function checkPassword(string $plain, string $hashed): bool
    {
        // Bcrypt
        if (str_starts_with($hashed, '$2y$') || str_starts_with($hashed, '$2a$')) {
            return Hash::check($plain, $hashed);
        }

        // Legacy SHA1
        return hash('sha1', $plain) === $hashed;
    }
}
