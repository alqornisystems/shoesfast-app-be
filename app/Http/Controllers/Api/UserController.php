<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FotoBase64;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Normalize phone number: remove leading 0 or 62
     */
    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $normalized = preg_replace('/\D/', '', $phone); // Remove non-digits

        if (str_starts_with($normalized, '62')) {
            $normalized = substr($normalized, 2); // Remove 62
        } elseif (str_starts_with($normalized, '0')) {
            $normalized = substr($normalized, 1); // Remove 0
        }

        return $normalized ?: null;
    }

    // GET /api/users
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 25);

        $users = User::with(['role', 'project'])
            ->orderBy('name')
            ->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $user->photo,
                'roles_id' => $user->roles_id,
                'role_name' => $user->role?->name,
                'projects_id' => $user->projects_id,
                'project_name' => $user->project?->name,
                'payment_date' => $user->payment_date,
                'account_number' => $user->account_number,
                'created_at' => $user->created_at,
            ];
        });

        return response()->json($users);
    }

    // POST /api/users
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:50', Rule::unique('users')->where('is_deleted', 0)],
            'phone' => ['nullable', 'string', 'max:25'],
            'password' => ['required', 'string', 'min:6'],
            'photo' => ['nullable', 'string'],
            'roles_id' => ['required', 'exists:roles,id'],
            'projects_id' => ['nullable', 'exists:projects,id'],
            'payment_date' => ['nullable', 'integer', 'min:1', 'max:31'],
            'account_number' => ['nullable', 'integer'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $this->normalizePhone($validated['phone'] ?? null),
            'password' => Hash::make($validated['password']),
            // Data URL disimpan ke disk; yang masuk kolom hanya jalurnya. Layar
            // karyawan mengizinkan berkas sampai 2 MB, dan sebagai base64 itu ~2,7 juta
            // karakter — jauh melewati batas kolom TEXT, dipotong MySQL tanpa galat.
            'photo' => ! empty($validated['photo'])
                ? FotoBase64::simpan($validated['photo'], 'users')
                : null,
            'roles_id' => $validated['roles_id'],
            'projects_id' => $validated['projects_id'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'created_by' => auth()->id() ?? 1,
            'modified_by' => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'Karyawan berhasil ditambahkan.',
            'data' => $user->load('role'),
        ], 201);
    }

    // GET /api/users/{user}
    public function show(User $user): JsonResponse
    {
        $user->load(['role', 'project']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $user->photo,
                'roles_id' => $user->roles_id,
                'role_name' => $user->role?->name,
                'projects_id' => $user->projects_id,
                'project_name' => $user->project?->name,
                'payment_date' => $user->payment_date,
                'account_number' => $user->account_number,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    // PUT /api/users/{user}
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:50', Rule::unique('users')->where('is_deleted', 0)->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25'],
            'password' => ['nullable', 'string', 'min:6'],
            'photo' => ['nullable', 'string'],
            'roles_id' => ['required', 'exists:roles,id'],
            'projects_id' => ['nullable', 'exists:projects,id'],
            'payment_date' => ['nullable', 'integer', 'min:1', 'max:31'],
            'account_number' => ['nullable', 'integer'],
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $this->normalizePhone($validated['phone'] ?? null),
            'roles_id' => $validated['roles_id'],
            'projects_id' => $validated['projects_id'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'modified_by' => auth()->id() ?? 1,
        ];

        // Only update password if provided
        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        // Only update photo if provided
        if (isset($validated['photo'])) {
            $data['photo'] = FotoBase64::simpan($validated['photo'], 'users');
        }

        $user->update($data);

        return response()->json([
            'message' => 'Karyawan berhasil diperbarui.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    /**
     * POST /api/users/{user}/reset-password
     *
     * Staf yang lupa kata sandinya terkunci total — ia tidak bisa absen, dan absen adalah
     * hal pertama yang dilakukannya pagi hari. Jalur yang sudah ada (`PUT /users/{id}`)
     * secara teknis bisa dipakai, tapi menuntut admin mengarang sendiri kata sandinya dan
     * mengirim seluruh badan formulir karyawan hanya untuk mengganti satu kolom.
     *
     * Yang dikembalikan adalah kata sandi sementara yang dibuat server. Admin membacakannya
     * ke karyawan, lalu karyawan menggantinya lewat `PUT /auth/change-password`.
     *
     * Ini BUKAN pemulihan mandiri. Alur mandiri lewat OTP menuntut keputusan pemilik soal
     * kanal pengiriman dan siapa yang menanggung risikonya, jadi tidak diputuskan di sini.
     */
    public function resetPassword(User $user): JsonResponse
    {
        // Dibuat server, bukan diketik admin: kata sandi yang diketik manusia berulang kali
        // berakhir menjadi pola yang sama untuk semua orang.
        $sementara = Str::password(10, true, true, false, false);

        $user->update([
            'password' => Hash::make($sementara),
            'modified_by' => auth()->id() ?? 1,
        ]);

        // Semua sesi lama dicabut. Kalau tidak, perangkat yang sudah masuk tetap masuk —
        // dan justru "akun ini dipakai orang lain" adalah salah satu alasan reset diminta.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Kata sandi sementara dibuat. Sampaikan ke karyawan dan minta segera diganti.',
            'temporary_password' => $sementara,
        ]);
    }

    // DELETE /api/users/{user}
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(['message' => 'Karyawan berhasil dihapus.']);
    }
}
