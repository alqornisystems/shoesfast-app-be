<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppService
 *
 * Pengirim WhatsApp via WAHA (WhatsApp HTTP API, self-hosted).
 * Endpoint kirim: POST {base}/api/sendText dengan header X-Api-Key.
 * Kredensial dari config('services.waha').
 */
class WhatsAppService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $session;
    protected bool $enabled;

    public function __construct()
    {
        // DB settings (editable from the admin panel) take precedence; fall back
        // to config/.env when a setting hasn't been overridden.
        $this->baseUrl = rtrim(Setting::read('waha_base_url', config('services.waha.base_url', '')), '/');
        $this->apiKey = Setting::read('waha_api_key', config('services.waha.api_key', ''));
        $this->session = Setting::read('waha_session', config('services.waha.session', 'default'));
        $this->enabled = Setting::readBool('waha_enabled', (bool) config('services.waha.enabled', false));
    }

    /**
     * Kirim pesan teks WhatsApp ke satu penerima.
     */
    public function sendMessage(string $phone, string $message): array
    {
        if (!$this->enabled) {
            Log::info('WhatsApp (WAHA) disabled. Message not sent.', ['phone' => $phone]);
            return ['status' => 'disabled', 'message' => 'WhatsApp is disabled'];
        }

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            Log::error('WAHA not configured (base_url/api_key missing)');
            return ['status' => 'error', 'message' => 'WhatsApp not configured'];
        }

        $chatId = $this->chatId($phone);

        try {
            $response = Http::withHeaders(['X-Api-Key' => $this->apiKey])
                ->acceptJson()
                ->post("{$this->baseUrl}/api/sendText", [
                    'session' => $this->session,
                    'chatId' => $chatId,
                    'text' => $message,
                    'linkPreview' => true,
                ]);

            $result = $response->json();

            if ($response->successful()) {
                Log::info('WhatsApp message sent', ['chatId' => $chatId, 'status' => $response->status()]);
                return ['status' => 'success', 'phone' => $chatId, 'response' => $result];
            }

            Log::error('WAHA API error', ['chatId' => $chatId, 'status' => $response->status(), 'response' => $result]);
            return ['status' => 'failed', 'phone' => $chatId, 'response' => $result];
        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', ['chatId' => $chatId, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'phone' => $chatId, 'message' => $e->getMessage()];
        }
    }

    /**
     * Kirim pesan ke banyak penerima (satu-per-satu dengan jeda kecil).
     *
     * @param array<int, array{phone: string, message: string}> $recipients
     */
    public function sendBulkMessages(array $recipients): array
    {
        if (!$this->enabled) {
            Log::info('WhatsApp (WAHA) disabled. Bulk messages not sent.', ['count' => count($recipients)]);
            return ['status' => 'disabled', 'message' => 'WhatsApp is disabled'];
        }

        $results = [];
        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'] ?? '';
            $message = $recipient['message'] ?? '';

            if (empty($phone) || empty($message)) {
                $results[] = ['status' => 'error', 'phone' => $phone, 'message' => 'Empty phone/message'];
                continue;
            }

            $results[] = $this->sendMessage($phone, $message);
            usleep(100000); // 100ms jeda antar pesan
        }

        return ['status' => 'success', 'count' => count($results), 'results' => $results];
    }

    /**
     * Ubah nomor menjadi chatId WAHA: 62xxxxxxxxxx@c.us
     */
    public function chatId(string $phone): string
    {
        return $this->normalizePhone($phone) . '@c.us';
    }

    /**
     * Normalisasi nomor ke format internasional tanpa "+" (62xxx).
     */
    public function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        $phone = ltrim($phone, '0');

        if (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }

    /**
     * Apakah pengiriman WhatsApp aktif & terkonfigurasi.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->baseUrl) && !empty($this->apiKey);
    }

    // ---------------------------------------------------------------------
    // Session management (WAHA) — powers the WhatsApp connection settings page.
    // These proxy WAHA's session endpoints so the frontend never needs the
    // WAHA base URL / API key directly.
    // ---------------------------------------------------------------------

    /**
     * Konfigurasi WAHA saat ini (untuk halaman pengaturan; tanpa API key).
     */
    public function configSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'configured' => ! empty($this->baseUrl) && ! empty($this->apiKey),
            'base_url' => $this->baseUrl,
            'session' => $this->session,
        ];
    }

    private function http()
    {
        return Http::withHeaders(['X-Api-Key' => $this->apiKey])->timeout(15);
    }

    /**
     * Pastikan WAHA aktif & terkonfigurasi sebelum memanggil API-nya.
     *
     * @return array|null  array error bila belum siap, null bila siap.
     */
    private function ensureReady(): ?array
    {
        if (! $this->enabled) {
            return ['ok' => false, 'reason' => 'disabled', 'message' => 'WhatsApp (WAHA) dinonaktifkan di server.'];
        }
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            return ['ok' => false, 'reason' => 'not_configured', 'message' => 'WAHA belum dikonfigurasi (base_url / api_key kosong).'];
        }

        return null;
    }

    /**
     * Info sesi: status koneksi (WORKING/SCAN_QR_CODE/STARTING/STOPPED/FAILED)
     * dan akun yang sedang terhubung.
     */
    public function getSessionInfo(): array
    {
        if ($err = $this->ensureReady()) {
            return $err;
        }

        try {
            $res = $this->http()->acceptJson()->get("{$this->baseUrl}/api/sessions/{$this->session}");

            if ($res->successful()) {
                $data = $res->json();

                return [
                    'ok' => true,
                    'status' => $data['status'] ?? 'UNKNOWN',
                    'me' => $data['me'] ?? null,
                ];
            }

            // 404 = sesi belum dibuat/dijalankan.
            return ['ok' => true, 'status' => 'STOPPED', 'me' => null];
        } catch (\Exception $e) {
            Log::warning('WAHA session info failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'unreachable', 'message' => 'Tidak dapat terhubung ke server WAHA.'];
        }
    }

    /**
     * Ambil QR code untuk scan sebagai data URL PNG base64.
     */
    public function getQr(): array
    {
        if ($err = $this->ensureReady()) {
            return $err;
        }

        try {
            $res = $this->http()
                ->withHeaders(['Accept' => 'image/png'])
                ->get("{$this->baseUrl}/api/{$this->session}/auth/qr");

            if (! $res->successful()) {
                return ['ok' => false, 'reason' => 'no_qr', 'message' => 'QR tidak tersedia (mungkin sudah terhubung atau sesi belum dimulai).'];
            }

            // Beberapa versi WAHA mengembalikan JSON {value: base64}, sebagian lagi PNG mentah.
            $contentType = (string) $res->header('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $json = $res->json();
                $value = $json['value'] ?? $json['qr'] ?? null;

                return ['ok' => (bool) $value, 'qr' => $value ? 'data:image/png;base64,'.$value : null];
            }

            return ['ok' => true, 'qr' => 'data:image/png;base64,'.base64_encode($res->body())];
        } catch (\Exception $e) {
            Log::warning('WAHA QR fetch failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'unreachable', 'message' => 'Tidak dapat terhubung ke server WAHA.'];
        }
    }

    /**
     * Jalankan aksi sesi WAHA: start | stop | restart | logout.
     */
    private function sessionAction(string $action): array
    {
        if ($err = $this->ensureReady()) {
            return $err;
        }

        try {
            $res = $this->http()->acceptJson()->post("{$this->baseUrl}/api/sessions/{$this->session}/{$action}");

            return ['ok' => $res->successful(), 'message' => $res->successful() ? null : 'WAHA menolak permintaan.'];
        } catch (\Exception $e) {
            Log::warning("WAHA session {$action} failed", ['error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'unreachable', 'message' => 'Tidak dapat terhubung ke server WAHA.'];
        }
    }

    public function startSession(): array
    {
        return $this->sessionAction('start');
    }

    public function stopSession(): array
    {
        return $this->sessionAction('stop');
    }

    public function restartSession(): array
    {
        return $this->sessionAction('restart');
    }

    public function logoutSession(): array
    {
        return $this->sessionAction('logout');
    }
}
