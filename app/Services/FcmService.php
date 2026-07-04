<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected string $serverKey;

    protected string $apiUrl;

    protected bool $enabled;

    public function __construct()
    {
        $this->serverKey = config('services.fcm.server_key', '');
        $this->apiUrl = config('services.fcm.url', 'https://fcm.googleapis.com/fcm/send');
        $this->enabled = config('services.fcm.enabled', false);
    }

    /**
     * Send notification to single device
     *
     * @param  string  $token  FCM device token
     * @param  string  $title  Notification title
     * @param  string  $body  Notification body
     * @param  array  $data  Additional data payload
     */
    public function sendToDevice(string $token, string $title, string $body, array $data = []): array
    {
        if (! $this->enabled) {
            Log::info('FCM disabled. Notification not sent.', ['token' => substr($token, 0, 20).'...']);

            return ['status' => 'disabled', 'message' => 'FCM is disabled'];
        }

        if (empty($this->serverKey)) {
            Log::error('FCM server key not configured');

            return ['status' => 'error', 'message' => 'FCM server key not configured'];
        }

        try {
            $payload = [
                'to' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'badge' => '1',
                ],
                'data' => $data,
                'priority' => 'high',
            ];

            $response = Http::withHeaders([
                'Authorization' => 'key='.$this->serverKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, $payload);

            $result = $response->json();

            Log::info('FCM notification sent to device', [
                'token' => substr($token, 0, 20).'...',
                'status' => $response->status(),
                'success' => $result['success'] ?? 0,
            ]);

            return [
                'status' => $response->successful() ? 'success' : 'failed',
                'response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('FCM send failed', [
                'token' => substr($token, 0, 20).'...',
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Send notification to multiple devices
     *
     * @param  array  $tokens  Array of FCM device tokens
     * @param  string  $title  Notification title
     * @param  string  $body  Notification body
     * @param  array  $data  Additional data payload
     */
    public function sendToDevices(array $tokens, string $title, string $body, array $data = []): array
    {
        if (! $this->enabled) {
            Log::info('FCM disabled. Notifications not sent.', ['count' => count($tokens)]);

            return ['status' => 'disabled', 'message' => 'FCM is disabled'];
        }

        if (empty($this->serverKey)) {
            Log::error('FCM server key not configured');

            return ['status' => 'error', 'message' => 'FCM server key not configured'];
        }

        // FCM allows max 1000 tokens per request
        $chunks = array_chunk($tokens, 1000);
        $results = [];

        foreach ($chunks as $chunk) {
            try {
                $payload = [
                    'registration_ids' => $chunk,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                        'badge' => '1',
                    ],
                    'data' => $data,
                    'priority' => 'high',
                ];

                $response = Http::withHeaders([
                    'Authorization' => 'key='.$this->serverKey,
                    'Content-Type' => 'application/json',
                ])->post($this->apiUrl, $payload);

                $result = $response->json();

                Log::info('FCM notifications sent to devices', [
                    'count' => count($chunk),
                    'status' => $response->status(),
                    'success' => $result['success'] ?? 0,
                    'failure' => $result['failure'] ?? 0,
                ]);

                $results[] = [
                    'status' => $response->successful() ? 'success' : 'failed',
                    'count' => count($chunk),
                    'response' => $result,
                ];
            } catch (\Exception $e) {
                Log::error('FCM batch send failed', [
                    'count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'status' => 'error',
                    'count' => count($chunk),
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'completed',
            'total_tokens' => count($tokens),
            'batches' => count($chunks),
            'results' => $results,
        ];
    }

    /**
     * Send notification to topic
     *
     * @param  string  $topic  FCM topic name (without /topics/ prefix)
     * @param  string  $title  Notification title
     * @param  string  $body  Notification body
     * @param  array  $data  Additional data payload
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        if (! $this->enabled) {
            Log::info('FCM disabled. Topic notification not sent.', ['topic' => $topic]);

            return ['status' => 'disabled', 'message' => 'FCM is disabled'];
        }

        if (empty($this->serverKey)) {
            Log::error('FCM server key not configured');

            return ['status' => 'error', 'message' => 'FCM server key not configured'];
        }

        try {
            $payload = [
                'to' => '/topics/'.$topic,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'badge' => '1',
                ],
                'data' => $data,
                'priority' => 'high',
            ];

            $response = Http::withHeaders([
                'Authorization' => 'key='.$this->serverKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, $payload);

            $result = $response->json();

            Log::info('FCM notification sent to topic', [
                'topic' => $topic,
                'status' => $response->status(),
                'message_id' => $result['message_id'] ?? null,
            ]);

            return [
                'status' => $response->successful() ? 'success' : 'failed',
                'response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('FCM topic send failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Send notification to user (karyawan/kurir/teknisi)
     * Compatible dengan legacy system: topic format user-{userId}
     *
     * @param  int  $userId  User ID
     * @param  string  $title  Notification title
     * @param  string  $body  Notification body
     * @param  string  $from  Source type (Teknisi, Kurir, Admin, dll)
     * @param  array  $data  Additional data payload
     */
    public function sendToUser(int $userId, string $title, string $body, string $from = 'System', array $data = []): array
    {
        // Format topic sesuai legacy: user-{userId}
        $topic = 'user-'.$userId;

        // Merge data dengan from
        $payload = array_merge([
            'to' => $from,
            'sound' => 1,
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'timestamp' => (string) time(),
        ], $data);

        return $this->sendToTopic($topic, $title, $body, $payload);
    }

    /**
     * Send treatment notification to technician
     *
     * @param  int  $technicianId  Technician user ID
     * @param  string  $orderCode  Order code
     * @param  string  $itemName  Item name
     * @param  string  $serviceName  Service name
     */
    public function sendTreatmentNotification(int $technicianId, string $orderCode, string $itemName, string $serviceName): array
    {
        $title = '🔧 Pengerjaan Baru Untukmu';
        $body = "Pesanan {$orderCode} - {$itemName}\nLayanan: {$serviceName}\n\nSegera dicek ya!";

        return $this->sendToUser($technicianId, $title, $body, 'Teknisi', [
            'type' => 'treatment',
            'order_code' => $orderCode,
            'action' => 'view_treatment',
        ]);
    }

    /**
     * Send pickup notification to courier
     *
     * @param  int  $courierId  Courier user ID
     * @param  int  $totalOrders  Number of orders to pickup
     * @param  string  $customerNames  Customer names (comma separated)
     */
    public function sendPickupNotification(int $courierId, int $totalOrders, string $customerNames): array
    {
        $title = '📦 Tugas Pickup Baru';
        $body = "Kamu mendapat {$totalOrders} pesanan untuk di-pickup:\n{$customerNames}\n\nSegera dicek ya!";

        return $this->sendToUser($courierId, $title, $body, 'Kurir', [
            'type' => 'pickup',
            'total_orders' => (string) $totalOrders,
            'action' => 'view_pickup',
        ]);
    }

    /**
     * Send delivery notification to courier
     *
     * @param  int  $courierId  Courier user ID
     * @param  int  $totalItems  Number of items to deliver
     * @param  string  $customerNames  Customer names (comma separated)
     */
    public function sendDeliveryAssignment(int $courierId, int $totalItems, string $customerNames): array
    {
        $title = '🚚 Tugas Delivery Baru';
        $body = "Kamu mendapat {$totalItems} barang untuk diantar:\n{$customerNames}\n\nSegera dicek ya!";

        return $this->sendToUser($courierId, $title, $body, 'Kurir', [
            'type' => 'delivery',
            'total_items' => (string) $totalItems,
            'action' => 'view_delivery',
        ]);
    }

    /**
     * Send notification to admin about item ready for delivery
     *
     * @param  int  $adminId  Admin user ID
     * @param  string  $orderCode  Order code
     * @param  string  $itemName  Item name
     */
    public function sendItemReadyNotification(int $adminId, string $orderCode, string $itemName): array
    {
        $title = '✅ Barang Siap Diantar';
        $body = "Pesanan {$orderCode}\nItem: {$itemName}\n\nSudah selesai dan siap diantar!";

        return $this->sendToUser($adminId, $title, $body, 'Admin', [
            'type' => 'item_ready',
            'order_code' => $orderCode,
            'action' => 'assign_courier',
        ]);
    }

    /**
     * Send notification to user (teknisi, kurir, admin, etc)
     *
     * @param  int  $userId  User ID
     * @param  string  $title  Notification title
     * @param  string  $message  Notification message
     * @param  string  $type  Notification type (treatment, delivery, etc)
     */
    public function sendUserNotification(int $userId, string $title, string $message, string $type = 'general'): array
    {
        // Build topic name based on user ID (format sama dengan legacy: user-{id})
        $topic = 'user-'.$userId;

        $data = [
            'type' => $type,
            'user_id' => (string) $userId,
            'timestamp' => (string) time(),
        ];

        return $this->sendToTopic($topic, $title, $message, $data);
    }

    /**
     * Check if FCM is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && ! empty($this->serverKey);
    }
}
