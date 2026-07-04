<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    // POST /api/auth/login
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone'          => ['required', 'string'],
            'password'       => ['required', 'string'],
            'remember_me'    => ['nullable', 'boolean'],
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

        // Revoke previous tokens and issue new one
        $user->tokens()->delete();

        // Set token expiration: 3 days if remember_me, otherwise default (no expiration for Sanctum)
        $tokenName = $request->input('remember_me') ? 'web-admin-remember' : 'web-admin';
        $token = $user->createToken($tokenName)->plainTextToken;

        // If remember_me, extend session lifetime to 3 days (4320 minutes)
        if ($request->input('remember_me')) {
            config(['session.lifetime' => 4320]);
        }

        $branchContext = app('branch.context');

        return response()->json([
            'message' => 'Login berhasil.',
            'token'   => $token,
            'user'    => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'role'         => $user->role?->name,
                'projects_id'  => $user->projects_id,
                'project_name' => $user->project?->name,
                'is_super_admin' => $user->projects_id === null,
            ],
            'branch' => [
                'active_id'   => $branchContext->getActiveBranch(),
                'active_name' => $branchContext->getActiveBranchName(),
                'can_switch'  => $branchContext->isSuperAdmin(),
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

    // POST /api/auth/switch-branch
    public function switchBranch(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $branchContext = app('branch.context');

        if (!$branchContext->isSuperAdmin()) {
            return response()->json([
                'message' => 'Only super admin can switch branches.',
            ], 403);
        }

        $branchId = $request->input('branch_id');
        $branchContext->switchBranch($branchId);

        return response()->json([
            'message' => 'Branch switched successfully.',
            'branch' => [
                'active_id'   => $branchContext->getActiveBranch(),
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
            'user' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'role'         => $user->role?->name,
                'projects_id'  => $user->projects_id,
                'project_name' => $user->project?->name,
                'is_super_admin' => $user->projects_id === null,
            ],
            'branch' => [
                'active_id'   => $branchContext->getActiveBranch(),
                'active_name' => $branchContext->getActiveBranchName(),
                'can_switch'  => $branchContext->isSuperAdmin(),
            ],
        ]);
    }

    // PUT /api/auth/profile
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:50'],
            'photo' => ['nullable', 'string'],
        ]);

        $user->update([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'photo'       => $validated['photo'] ?? $user->photo,
            'modified_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user'    => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'phone'        => $user->phone,
                'photo'        => $user->photo,
                'role'         => $user->role?->name,
                'projects_id'  => $user->projects_id,
                'project_name' => $user->project?->name,
            ],
        ]);
    }

    // PUT /api/auth/change-password
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        // Verify current password
        if (!$this->checkPassword($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Password lama tidak sesuai.',
            ], 422);
        }

        // Update to new password (bcrypt)
        $user->update([
            'password'    => Hash::make($validated['new_password']),
            'modified_by' => $user->id,
        ]);

        // Revoke all tokens to force re-login
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan login kembali.',
        ]);
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
