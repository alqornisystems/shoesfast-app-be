<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppService
 *
 * Pengirim WhatsApp via Wablas (gateway hosted). Endpoint kirim:
 * POST {url}/api/send-message dengan header Authorization: {token}.{secret}.
 * Kredensial dari config('services.wablas') / DB settings (bisa diubah dari admin panel).
 */
class WhatsAppService
{
    protected string $baseUrl;
    protected string $token;
    protected string $secret;
    protected bool $enabled;

    public function __construct()
    {
        // DB settings (editable dari admin panel) diprioritaskan; fallback ke .env/config.
        $this->baseUrl = rtrim(Setting::read('wablas_url', config('services.wablas.url', '')), '/');
        $this->token = Setting::read('wablas_token', config('services.wablas.token', ''));
        $this->secret = Setting::read('wablas_secret', config('services.wablas.secret', ''));
        $this->enabled = Setting::readBool('wablas_enabled', (bool) config('services.wablas.enabled', false));
    }

    /**
     * Header Authorization Wablas. Secure mode: "token.secret".
     */
    protected function authHeader(): string
    {
        return $this->secret !== '' ? "{$this->token}.{$this->secret}" : $this->token;
    }

    /**
     * Kirim pesan teks WhatsApp ke satu penerima.
     */
    public function sendMessage(string $phone, string $message): array
    {
        if (!$this->enabled) {
            Log::info('WhatsApp (Wablas) disabled. Message not sent.', ['phone' => $phone]);
            return ['status' => 'disabled', 'message' => 'WhatsApp is disabled'];
        }

        if (empty($this->baseUrl) || empty($this->token)) {
            Log::error('Wablas not configured (url/token missing)');
            return ['status' => 'error', 'message' => 'WhatsApp not configured'];
        }

        $to = $this->normalizePhone($phone);

        try {
            $response = Http::withHeaders(['Authorization' => $this->authHeader()])
                ->asForm()
                ->post("{$this->baseUrl}/api/send-message", [
                    'phone' => $to,
                    'message' => $message,
                ]);

            $result = $response->json();
            $ok = $response->successful() && ($result['status'] ?? false) === true;

            if ($ok) {
                Log::info('WhatsApp message sent (Wablas)', ['phone' => $to, 'status' => $response->status()]);
                return ['status' => 'success', 'phone' => $to, 'response' => $result];
            }

            Log::error('Wablas API error', ['phone' => $to, 'status' => $response->status(), 'response' => $result]);
            return ['status' => 'failed', 'phone' => $to, 'response' => $result];
        } catch (\Exception $e) {
            Log::error('WhatsApp send failed (Wablas)', ['phone' => $to, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'phone' => $to, 'message' => $e->getMessage()];
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
            Log::info('WhatsApp (Wablas) disabled. Bulk messages not sent.', ['count' => count($recipients)]);
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
     * Normalisasi nomor ke format internasional tanpa "+" (62xxx) — yang dipakai Wablas.
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
        return $this->enabled && !empty($this->baseUrl) && !empty($this->token);
    }

    // ---------------------------------------------------------------------
    // Untuk halaman pengaturan WhatsApp di admin panel.
    // ---------------------------------------------------------------------

    /**
     * Ringkasan konfigurasi (tanpa token/secret).
     */
    public function configSummary(): array
    {
        return [
            'driver' => 'wablas',
            'enabled' => $this->enabled,
            'configured' => !empty($this->baseUrl) && !empty($this->token),
            'base_url' => $this->baseUrl,
        ];
    }

    /**
     * URL halaman scan QR Wablas (untuk di-embed di halaman pengaturan).
     * Wablas memakai token (tanpa secret) pada query param.
     */
    public function getScanUrl(): ?string
    {
        if (empty($this->baseUrl) || empty($this->token)) {
            return null;
        }

        return "{$this->baseUrl}/api/device/scan?token={$this->token}";
    }

    /**
     * Status device Wablas (connected/disconnected). Wablas /api/device/info
     * memakai token pada query param.
     */
    public function getDeviceStatus(): array
    {
        if (!$this->enabled) {
            return ['ok' => false, 'reason' => 'disabled', 'message' => 'WhatsApp (Wablas) dinonaktifkan.'];
        }
        if (empty($this->baseUrl) || empty($this->token)) {
            return ['ok' => false, 'reason' => 'not_configured', 'message' => 'Wablas belum dikonfigurasi (url / token kosong).'];
        }

        try {
            $res = Http::timeout(15)->acceptJson()
                ->get("{$this->baseUrl}/api/device/info", ['token' => $this->token]);
            $data = $res->json();

            // Wablas: { status: true, data: { status: "connected"/"disconnected", ... } }
            $deviceStatus = $data['data']['status'] ?? ($data['status'] ?? null);
            $connected = is_string($deviceStatus) && strtolower($deviceStatus) === 'connected';

            return [
                'ok' => (bool) ($data['status'] ?? false),
                'status' => $deviceStatus,
                'connected' => $connected,
                'data' => $data['data'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning('Wablas device info failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'unreachable', 'message' => 'Tidak dapat terhubung ke server Wablas.'];
        }
    }
}
