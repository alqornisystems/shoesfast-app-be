<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_PIN_ATTEMPTS = 5;

    private const PIN_LOCKOUT_SECONDS = 900; // 15 menit

    /**
     * Buang non-digit lalu awalan 62 atau 0. Data produksi menyimpan 4.605 dari
     * 4.664 nomor dalam bentuk '8xxx', jadi bentuk itu yang jadi kanonik.
     * Cermin dari Api\AuthController::normalizePhone milik staf.
     */
    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D/', '', $phone);

        if (str_starts_with($normalized, '62')) {
            return substr($normalized, 2);
        }

        if (str_starts_with($normalized, '0')) {
            return substr($normalized, 1);
        }

        return $normalized;
    }

    /**
     * 386 nomor dipakai lebih dari satu pelanggan (846 baris). Yang menang
     * adalah baris terlama; sisanya tidak pernah terlihat dari portal.
     */
    private function findByPhone(string $normalizedPhone): ?Customer
    {
        return Customer::withoutGlobalScope('branch')
            ->where('phone', $normalizedPhone)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    // Publik karena ProfileController dan MembershipController memakai bentuk
    // customer yang sama persis. Satu tempat, supaya portal tidak pernah
    // menerima bentuk berbeda tergantung endpoint mana yang menjawab.
    public function presentPublic(Customer $customer): array
    {
        $customer->loadMissing('project');

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'instagram' => $customer->instagram,
            'date_of_birth' => $customer->date_of_birth !== null ? (int) $customer->date_of_birth : null,
            // Kolomnya menyimpan dua bentuk: URL absolut milik data lama, dan
            // data URL base64 untuk unggahan baru dari panel admin maupun
            // portal. Keduanya sama-sama bisa dipasang langsung di <img>,
            // jadi diteruskan apa adanya.
            'photo' => $customer->photo,
            'address' => $customer->address,
            'maps' => $customer->maps,
            'latitude' => $customer->latitude,
            'longitude' => $customer->longitude,
            'is_member' => (int) $customer->is_member,
            'member_code' => $customer->member_code,
            'points' => (int) $customer->points,
            'projects_id' => $customer->projects_id,
            'project_name' => $customer->project?->name,
        ];
    }

    private function issueToken(Customer $customer): string
    {
        return $customer->createToken('portal-pelanggan')->plainTextToken;
    }

    // POST /api/customer/auth/check-phone
    public function checkPhone(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string']]);

        $customer = $this->findByPhone($this->normalizePhone($request->phone));

        return response()->json([
            'exists' => (bool) $customer,
            'has_pin' => (bool) $customer?->pin,
            'name' => $customer?->name,
        ]);
    }

    // POST /api/customer/auth/set-pin
    public function setPin(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'pin' => ['required', 'digits:6'],
        ]);

        $customer = $this->findByPhone($this->normalizePhone($request->phone));

        if (! $customer) {
            return response()->json(['message' => 'Nomor HP belum terdaftar.'], 404);
        }

        if ($customer->pin) {
            return response()->json([
                'message' => 'PIN sudah pernah dibuat. Gunakan lupa PIN bila lupa.',
            ], 422);
        }

        // Jejak klaim: nol pelanggan punya PIN saat portal diluncurkan, jadi
        // siapa pun yang mengetik nomor lebih dulu menguasai akunnya. Dicatat
        // supaya sengketa bisa ditelusuri dan admin bisa mereset.
        $customer->update([
            'pin' => Hash::make($request->pin),
            'pin_created_at' => time(),
            'pin_created_ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $this->issueToken($customer),
            'customer' => $this->presentPublic($customer),
        ]);
    }

    // POST /api/customer/auth/login
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'pin' => ['required', 'string'],
        ]);

        $phone = $this->normalizePhone($request->phone);

        // Kunci per NOMOR, bukan per IP. PIN 6 digit hanya punya sejuta
        // kemungkinan; throttle per IP tidak menahan penyerang yang berpindah
        // jaringan, dan menahan seluruh warnet kalau satu orang salah ketik.
        $throttleKey = 'customer-pin:'.$phone;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_PIN_ATTEMPTS)) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.',
            ], 429);
        }

        $customer = $this->findByPhone($phone);

        if (! $customer || ! $customer->pin || ! Hash::check($request->pin, $customer->pin)) {
            RateLimiter::hit($throttleKey, self::PIN_LOCKOUT_SECONDS);

            return response()->json(['message' => 'Nomor HP atau PIN salah.'], 401);
        }

        RateLimiter::clear($throttleKey);

        return response()->json([
            'token' => $this->issueToken($customer),
            'customer' => $this->presentPublic($customer),
        ]);
    }

    // POST /api/customer/auth/register
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string'],
            'pin' => ['required', 'digits:6'],
        ]);

        $phone = $this->normalizePhone($request->phone);

        if ($this->findByPhone($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor HP sudah terdaftar. Silakan masuk.',
            ]);
        }

        // projects_id tidak boleh dari klien. Cabang default diambil dari
        // settings; admin memindahkan pelanggan bila ternyata salah cabang.
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => (int) (Setting::where('key', 'default_branch_id')->value('value') ?: 1),
            'name' => $request->name,
            'phone' => $phone,
            'pin' => Hash::make($request->pin),
            'pin_created_at' => time(),
            'pin_created_ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $this->issueToken($customer),
            'customer' => $this->presentPublic($customer),
        ], 201);
    }

    // POST /api/customer/auth/forgot-pin
    public function forgotPin(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string']]);

        $customer = $this->findByPhone($this->normalizePhone($request->phone));

        // Jawaban untuk nomor tak dikenal sengaja identik dengan jawaban untuk
        // nomor tanpa email. Kalau berbeda, endpoint ini jadi alat menyapu
        // nomor mana yang terdaftar.
        if (! $customer || ! $customer->email) {
            return response()->json([
                'channel' => 'toko',
                'message' => 'Hubungi toko untuk mereset PIN kamu.',
            ]);
        }

        $newPin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $customer->update([
            'pin' => Hash::make($newPin),
            'pin_created_at' => time(),
            'pin_created_ip' => $request->ip(),
        ]);

        // Token lama dicabut: PIN lama sudah tidak berlaku, sesi lama juga.
        $customer->tokens()->delete();

        Mail::raw(
            "PIN baru Shoesfast kamu: {$newPin}\n\nJangan bagikan ke siapa pun.",
            fn ($message) => $message->to($customer->email)->subject('PIN Baru Shoesfast')
        );

        return response()->json([
            'channel' => 'email',
            'message' => 'PIN baru sudah dikirim ke email kamu.',
        ]);
    }

    // GET /api/customer/auth/me
    public function me(Request $request): JsonResponse
    {
        return response()->json(['customer' => $this->presentPublic($request->user())]);
    }

    // POST /api/customer/auth/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Keluar berhasil.']);
    }
}
