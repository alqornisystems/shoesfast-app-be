<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppService
 *
 * Pengirim WhatsApp resmi via WhatsApp Cloud API (Meta / graph.facebook.com).
 * Menggantikan WablasService. Kredensial diambil dari config('services.whatsapp').
 */
class WhatsAppService
{
    protected string $apiUrl;
    protected string $version;
    protected string $phoneNumberId;
    protected string $token;
    protected bool $enabled;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.whatsapp.url', 'https://graph.facebook.com'), '/');
        $this->version = config('services.whatsapp.version', 'v21.0');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id', '');
        $this->token = config('services.whatsapp.token', '');
        $this->enabled = config('services.whatsapp.enabled', false);
    }

    /**
     * Kirim pesan teks WhatsApp ke satu penerima.
     */
    public function sendMessage(string $phone, string $message): array
    {
        if (!$this->enabled) {
            Log::info('WhatsApp disabled. Message not sent.', ['phone' => $phone]);
            return ['status' => 'disabled', 'message' => 'WhatsApp is disabled'];
        }

        if (empty($this->token) || empty($this->phoneNumberId)) {
            Log::error('WhatsApp Cloud API not configured (token/phone_number_id missing)');
            return ['status' => 'error', 'message' => 'WhatsApp not configured'];
        }

        $to = $this->normalizePhone($phone);

        try {
            $response = Http::withToken($this->token)
                ->post("{$this->apiUrl}/{$this->version}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            $result = $response->json();

            if ($response->successful()) {
                Log::info('WhatsApp message sent', ['phone' => $to, 'status' => $response->status()]);
                return ['status' => 'success', 'phone' => $to, 'response' => $result];
            }

            Log::error('WhatsApp API error', ['phone' => $to, 'status' => $response->status(), 'response' => $result]);
            return ['status' => 'failed', 'phone' => $to, 'response' => $result];
        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', ['phone' => $to, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'phone' => $to, 'message' => $e->getMessage()];
        }
    }

    /**
     * Kirim pesan ke banyak penerima.
     * Cloud API tidak punya endpoint bulk, jadi dikirim satu-per-satu dengan jeda kecil.
     *
     * @param array<int, array{phone: string, message: string}> $recipients
     */
    public function sendBulkMessages(array $recipients): array
    {
        if (!$this->enabled) {
            Log::info('WhatsApp disabled. Bulk messages not sent.', ['count' => count($recipients)]);
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
     * Normalisasi nomor ke format internasional tanpa "+" (62xxx) sesuai Cloud API.
     */
    protected function normalizePhone(string $phone): string
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
        return $this->enabled && !empty($this->token) && !empty($this->phoneNumberId);
    }
}
