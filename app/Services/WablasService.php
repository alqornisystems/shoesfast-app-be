<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WablasService
{
    protected string $apiUrl;
    protected string $token;
    protected bool $enabled;

    public function __construct()
    {
        $this->apiUrl = config('services.wablas.url', 'https://console.wablas.com');
        $this->token = config('services.wablas.token', '');
        $this->enabled = config('services.wablas.enabled', false);
    }

    /**
     * Send WhatsApp message to single recipient
     */
    public function sendMessage(string $phone, string $message): array
    {
        if (!$this->enabled) {
            Log::info('Wablas disabled. Message not sent.', ['phone' => $phone]);
            return ['status' => 'disabled', 'message' => 'Wablas is disabled'];
        }

        if (empty($this->token)) {
            Log::error('Wablas token not configured');
            return ['status' => 'error', 'message' => 'Wablas token not configured'];
        }

        $phone = $this->normalizePhone($phone);

        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->post("{$this->apiUrl}/api/send-message", [
                    'phone' => $phone,
                    'message' => $message,
                ]);

            $result = $response->json();

            Log::info('Wablas message sent', ['phone' => $phone, 'status' => $response->status()]);

            return [
                'status' => $response->successful() ? 'success' : 'failed',
                'phone' => $phone,
                'response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('Wablas send failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'phone' => $phone, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send WhatsApp message to multiple recipients using v2 API
     * Menggunakan Wablas API v2 untuk bulk messaging (lebih efisien)
     */
    public function sendBulkMessages(array $recipients): array
    {
        if (!$this->enabled) {
            Log::info('Wablas disabled. Bulk messages not sent.', ['count' => count($recipients)]);
            return ['status' => 'disabled', 'message' => 'Wablas is disabled'];
        }

        if (empty($this->token)) {
            Log::error('Wablas token not configured');
            return ['status' => 'error', 'message' => 'Wablas token not configured'];
        }

        // Normalize phone numbers
        $data = array_map(function($recipient) {
            return [
                'phone' => $this->normalizePhone($recipient['phone'] ?? ''),
                'message' => $recipient['message'] ?? '',
            ];
        }, $recipients);

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/api/v2/send-message", [
                'data' => $data,
            ]);

            $result = $response->json();

            Log::info('Wablas bulk messages sent', ['count' => count($data), 'status' => $response->status()]);

            return [
                'status' => $response->successful() ? 'success' : 'failed',
                'count' => count($data),
                'response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('Wablas bulk send failed', ['count' => count($data), 'error' => $e->getMessage()]);
            return ['status' => 'error', 'count' => count($data), 'message' => $e->getMessage()];
        }
    }

    /**
     * Send WhatsApp message to multiple recipients (one by one)
     * Legacy method - use sendBulkMessages() for better performance
     */
    public function sendBulkMessagesOneByOne(array $recipients): array
    {
        $results = [];
        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'] ?? '';
            $message = $recipient['message'] ?? '';

            if (empty($phone) || empty($message)) {
                $results[] = ['status' => 'error', 'phone' => $phone, 'message' => 'Empty phone/message'];
                continue;
            }

            $results[] = $this->sendMessage($phone, $message);
            usleep(100000); // 100ms delay between messages
        }

        return $results;
    }

    /**
     * Normalize phone number to Wablas format (62xxx)
     */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        $phone = ltrim($phone, '0');
        if (str_starts_with($phone, '62')) {
            $phone = substr($phone, 2);
        }
        return '62' . $phone;
    }

    /**
     * Check if Wablas is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->token);
    }
}
