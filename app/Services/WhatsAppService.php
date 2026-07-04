<?php

namespace App\Services;

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
        $this->baseUrl = rtrim(config('services.waha.base_url', ''), '/');
        $this->apiKey = config('services.waha.api_key', '');
        $this->session = config('services.waha.session', 'default');
        $this->enabled = config('services.waha.enabled', false);
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
}
