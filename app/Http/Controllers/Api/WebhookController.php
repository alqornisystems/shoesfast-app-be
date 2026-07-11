<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OfflineMessage;
use App\Models\Order;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(protected WhatsAppService $whatsapp)
    {
    }

    /**
     * Wablas incoming-message webhook.
     * POST /api/webhook -> terima pesan masuk dari Wablas.
     */
    public function whatsapp(Request $request)
    {
        // Verifikasi secret opsional (jika WABLAS_WEBHOOK_SECRET diset) — Wablas bisa
        // mengirimkannya lewat query param ?secret= atau field payload.
        $secret = config('services.wablas.webhook_secret');
        if (!empty($secret)) {
            $provided = (string) ($request->query('secret') ?? data_get($request->all(), 'secret', ''));
            if (!hash_equals((string) $secret, $provided)) {
                Log::warning('Wablas webhook secret mismatch');
                return response()->json(['success' => false, 'message' => 'Invalid secret'], 401);
            }
        }

        try {
            // Log incoming webhook for debugging
            Log::info('Wablas Webhook Received', $request->all());

            // Payload Wablas ada di top-level, sebagian versi membungkus di "data".
            $all = $request->all();
            $payload = is_array(data_get($all, 'data')) ? data_get($all, 'data') : $all;

            // Abaikan pesan grup & pesan yang kita kirim sendiri
            $isGroup = filter_var(data_get($payload, 'isGroup', false), FILTER_VALIDATE_BOOLEAN);
            $fromMe = filter_var(data_get($payload, 'fromMe', false), FILTER_VALIDATE_BOOLEAN);
            if ($fromMe) {
                return response()->json(['success' => true, 'message' => 'Ignored own message']);
            }

            // Nomor pengirim: field "phone"/"sender"/"from"
            $rawFrom = (string) (data_get($payload, 'phone')
                ?: data_get($payload, 'sender')
                ?: data_get($payload, 'from', ''));
            $phone = preg_replace('/\D/', '', $rawFrom);       // 6281234567890

            // Tipe & isi pesan: Wablas pakai "text" untuk teks
            $type = (string) (data_get($payload, 'messageType') ?: data_get($payload, 'type', 'text'));
            $messageType = $type === '' ? 'text' : $type;
            $message = trim((string) (data_get($payload, 'message')
                ?: data_get($payload, 'body')
                ?: data_get($payload, 'text', '')));

            // Get current time and day
            $currentHour = (int) date('H');
            $currentDay = date('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $today = date('Y-m-d');

            // Holiday period (Lebaran)
            $startHoliday = '2025-03-30';
            $endHoliday = '2025-04-06';

            // Hanya proses pesan teks individual (bukan grup) dengan pengirim valid
            if ($phone !== '' && $messageType === 'text' && !$isGroup) {
                // Normalize phone number
                $normalizedPhone = $this->normalizePhone($phone);

                // Check if sender is a customer
                $customer = Customer::where('phone', $normalizedPhone)
                    ->where('is_deleted', 0)
                    ->first();

                // Check if message is an order form
                $orderData = $this->parseOrderForm($message);
                if ($orderData) {
                    DB::beginTransaction();
                    try {
                        // Auto-register customer if not exists
                        if (!$customer) {
                            $customer = $this->autoRegisterCustomer($normalizedPhone, $orderData);
                        }

                        // Auto-create order
                        $order = $this->autoCreateOrder($customer, $orderData);

                        DB::commit();

                        // Send confirmation message
                        $confirmMessage = "✅ *Pesanan kamu sudah tercatat!*\n\n"
                            . "No. Invoice: *{$order->code}*\n\n"
                            . "📋 *Detail:*\n"
                            . "• Nama: {$customer->name}\n"
                            . "• Barang: " . ($orderData['items'] ?? 'Belum diisi') . "\n"
                            . "• Alamat: " . ($orderData['address'] ?? 'Belum diisi') . "\n\n"
                            . "📌 *Status:* Menunggu konfirmasi admin\n\n"
                            . "Simpan nomor invoice di atas ya kak. Admin kami akan segera menghubungi untuk konfirmasi pickup & pembayaran. 😊\n\n"
                            . "💰 Untuk transaksi di bawah Rp 500.000, pembayaran wajib di awal ya!\n\n"
                            . "- *SHOESFAST* -";

                        $this->sendWhatsAppMessage($phone, $confirmMessage);

                        return response()->json([
                            'success' => true,
                            'message' => 'Order created and customer registered',
                            'customer_id' => $customer->id,
                            'order_id' => $order->id,
                            'order_code' => $order->code,
                            'order_data' => $orderData,
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Failed to process order form', [
                            'error' => $e->getMessage(),
                            'phone' => $phone,
                            'order_data' => $orderData,
                        ]);

                        // Send error message to customer
                        $errorMessage = "❌ Maaf, terjadi kesalahan saat memproses order Anda.\n\n"
                            . "Silakan hubungi admin kami langsung untuk bantuan lebih lanjut. 🙏\n\n"
                            . "- *SHOESFAST* -";

                        $this->sendWhatsAppMessage($phone, $errorMessage);

                        throw $e;
                    }
                }

                // If not a customer, send auto-reply based on message content
                if (!$customer && !empty($message)) {
                    $autoReply = $this->getAutoReply($message);
                    if ($autoReply) {
                        $this->sendWhatsAppMessage($phone, $autoReply);
                        return response()->json([
                            'success' => true,
                            'message' => 'Auto-reply sent',
                            'reply' => $autoReply,
                        ]);
                    }
                }

                // Check if already sent offline message today
                $offlineLog = OfflineMessage::where('phone', $normalizedPhone)
                    ->where('date', $today)
                    ->first();

                if (!$offlineLog) {
                    // Check if during holiday period
                    if ($today >= $startHoliday && $today <= $endHoliday) {
                        $holidayMessage = "Halo Sobat *SHOESFAST* 👋🏻\n\n"
                            . "Terima kasih sudah menghubungi kami! 🤗\n\n"
                            . "Saat ini kami sedang *LIBUR LEBARAN* mulai *30 Maret - 6 April 2025*.\n\n"
                            . "📌 *Pesan kamu sudah masuk dalam antrian* dan akan kami balas setelah tim kami kembali aktif pada *7 April 2025*.\n\n"
                            . "Selamat merayakan Hari Raya Idul Fitri 1446H! 🌙✨\n"
                            . "Mohon maaf lahir dan batin. 🙏😊\n\n"
                            . "- *SHOESFAST* -\n"
                            . "Pesan sambil tiduran 😊";

                        $this->sendOfflineMessage($normalizedPhone, $holidayMessage, $today);

                        return response()->json([
                            'success' => true,
                            'message' => 'Holiday message sent',
                        ]);
                    }

                    // Check if Sunday (day off)
                    if ($currentDay == 0) {
                        $sundayMessage = "Halo Sobat *SHOESFAST* 👋🏻\n\n"
                            . "Terima kasih sudah menghubungi kami! 🤗\n\n"
                            . "Saat ini kami sedang *LIBUR* karena hari Minggu ⏳. "
                            . "Mohon maaf jika respon kami lebih lambat dari biasanya. 🙏\n\n"
                            . "📌 *Pesan kamu sudah masuk dalam antrian* dan akan kami balas secepatnya saat tim kami kembali aktif. "
                            . "Terima kasih atas kesabaran dan pengertiannya! 😊\n\n"
                            . "- *SHOESFAST* -\n"
                            . "Pesan sambil tiduran 😊";

                        $this->sendOfflineMessage($normalizedPhone, $sundayMessage, $today);

                        return response()->json([
                            'success' => true,
                            'message' => 'Sunday message sent',
                        ]);
                    }

                    // Check if outside office hours (08:00 - 15:00 WIB)
                    if ($currentHour < 8 || $currentHour >= 15) {
                        $offlineMessage = "Halo Sobat *SHOESFAST* 👋🏻\n\n"
                            . "Terima kasih sudah menghubungi kami! 🤗\n\n"
                            . "Saat ini kami sedang di luar jam operasional ⏳, yaitu *08:00 - 15:00 WIB*. "
                            . "Mohon maaf jika respon kami lebih lambat dari biasanya. 🙏\n\n"
                            . "📌 *Pesan kamu sudah masuk dalam antrian* dan akan kami balas secepatnya saat tim kami kembali aktif. "
                            . "Terima kasih atas kesabaran dan pengertiannya! 😊\n\n"
                            . "- *SHOESFAST* -\n"
                            . "Pesan sambil tiduran 😊";

                        $this->sendOfflineMessage($normalizedPhone, $offlineMessage, $today);

                        return response()->json([
                            'success' => true,
                            'message' => 'Offline hours message sent',
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed',
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp Webhook Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Normalize phone number (remove leading 0 or 62)
     */
    private function normalizePhone(string $phone): string
    {
        // Remove all non-numeric characters
        $normalized = preg_replace('/\D/', '', $phone);

        // Remove 62 or 0 prefix
        if (str_starts_with($normalized, '62')) {
            $normalized = substr($normalized, 2);
        } elseif (str_starts_with($normalized, '0')) {
            $normalized = substr($normalized, 1);
        }

        return $normalized;
    }

    /**
     * Send offline message and log it
     */
    private function sendOfflineMessage(string $phone, string $message, string $date): void
    {
        // Send WhatsApp message
        $this->sendWhatsAppMessage($phone, $message);

        // Log offline message
        OfflineMessage::create([
            'phone' => $phone,
            'date' => $date,
            'message' => $message,
            'created_by' => null, // System generated
        ]);
    }

    /**
     * Send WhatsApp message via WhatsApp Cloud API.
     */
    private function sendWhatsAppMessage(string $phone, string $message): array
    {
        return $this->whatsapp->sendMessage($phone, $message);
    }

    /**
     * Get auto-reply based on message content
     */
    private function getAutoReply(string $message): ?string
    {
        $message = mb_strtolower(trim($message));

        // Greeting keywords
        $greetings = [
            'hello', 'helo', 'hai', 'halo', 'hi', 'hei', 'hey',
            'pagi', 'siang', 'sore', 'malam',
            'assalamualaikum', 'ass', 'salam',
            'min', 'admin', 'bro', 'sis',
        ];

        // Location keywords
        $locationKeywords = [
            'dimana', 'lokasi', 'alamat', 'tempat', 'maps', 'arah',
        ];

        // Payment keywords
        $transferKeywords = [
            'transfer', 'bayar', 'rekening', 'pembayaran', 'ovo', 'dana',
        ];

        // Pickup keywords
        $pickupKeywords = [
            'pickup', 'diambil', 'jemput', 'kurir',
        ];

        // Service keywords
        $serviceKeywords = [
            'layanan', 'service', 'bisa apa', 'ada apa', 'paket',
        ];

        // Check for greeting — reply with the welcome + order form so the
        // customer can fill it and get an order auto-created (see parseOrderForm).
        foreach ($greetings as $keyword) {
            if (str_contains($message, $keyword)) {
                return $this->orderFormMessage();
            }
        }

        // Check for location
        foreach ($locationKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return "📍 *Lokasi SHOESFAST*\n\n"
                    . "🏠 Alamat:\n"
                    . "Jl. Cibaduyut Raya No. 123\n"
                    . "Bandung, Jawa Barat 40239\n\n"
                    . "🕒 Jam Operasional:\n"
                    . "Senin - Sabtu: 08:00 - 15:00 WIB\n"
                    . "Minggu: Libur\n\n"
                    . "📱 Kontak:\n"
                    . "WhatsApp: 0897-0830-732\n\n"
                    . "🗺️ Maps: https://maps.app.goo.gl/shoesfast\n\n"
                    . "- *SHOESFAST* -\n"
                    . "Pesan sambil tiduran 😊";
            }
        }

        // Check for payment
        foreach ($transferKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return "💳 *Metode Pembayaran SHOESFAST*\n\n"
                    . "Kami menerima pembayaran melalui:\n\n"
                    . "🏦 Transfer Bank:\n"
                    . "• BCA: 1234567890 (a/n PT Shoesfast)\n"
                    . "• Mandiri: 9876543210 (a/n PT Shoesfast)\n\n"
                    . "💰 E-Wallet:\n"
                    . "• OVO: 0897-0830-732\n"
                    . "• DANA: 0897-0830-732\n\n"
                    . "💵 Cash (di toko)\n\n"
                    . "Setelah transfer, mohon kirim bukti pembayaran ya! 📸\n\n"
                    . "- *SHOESFAST* -\n"
                    . "Pesan sambil tiduran 😊";
            }
        }

        // Check for pickup — send the order form so it can be auto-processed
        foreach ($pickupKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return $this->orderFormMessage();
            }
        }

        // Check for services
        foreach ($serviceKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return "🛠️ *Layanan SHOESFAST*\n\n"
                    . "Kami menyediakan berbagai layanan perawatan sepatu & tas:\n\n"
                    . "👟 *Sepatu:*\n"
                    . "• Express Clean\n"
                    . "• Deep Clean\n"
                    . "• Repaint\n"
                    . "• Repair\n"
                    . "• Unyellowing\n\n"
                    . "👜 *Tas:*\n"
                    . "• Bag Spa\n"
                    . "• Repair\n"
                    . "• Recolor\n\n"
                    . "💰 Harga mulai dari Rp 25.000\n"
                    . "⏱️ Estimasi 3-7 hari kerja\n\n"
                    . "Untuk info lebih detail, silakan chat admin kami! 😊\n\n"
                    . "- *SHOESFAST* -\n"
                    . "Pesan sambil tiduran 😊";
            }
        }

        return null;
    }

    /**
     * Welcome + order form message. When a customer replies with this form
     * filled in, parseOrderForm() picks it up and an order is auto-created.
     */
    private function orderFormMessage(): string
    {
        return "Selamat datang di layanan *SHOESFAST* 👋🏻\n\n"
            . "Jam Operasional jasa pelayanan kurir cuci *14.00-17.00* ✨\n"
            . "Untuk layanan pickup delivery bisa isi Form order berikut ya kak :\n\n"
            . "Nama : \n"
            . "Alamat : \n"
            . "No WA : \n"
            . "Instagram : \n"
            . "Barang yg diambil : \n\n"
            . "Cukup balas pesan ini dengan form yang sudah diisi, ya kak 😊";
    }

    /**
     * Parse order form from message
     * Format expected:
     * Nama : John Doe
     * Email : john@example.com
     * Alamat : Jl. Example No. 123
     * No WA : 08123456789
     * Instagram : @johndoe
     * Barang yg diambil : Sepatu Nike
     */
    private function parseOrderForm(string $message): ?array
    {
        // Check if message contains form keywords
        if (!str_contains(mb_strtolower($message), 'nama') ||
            !str_contains(mb_strtolower($message), ':')) {
            return null;
        }

        $data = [];
        $lines = explode("\n", $message);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Parse key-value pairs
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key = mb_strtolower(trim($key));
                $value = trim($value);

                // Skip if value is empty
                if (empty($value)) continue;

                // Map keys to data array
                if (str_contains($key, 'nama')) {
                    $data['name'] = $value;
                } elseif (str_contains($key, 'email')) {
                    $data['email'] = $value;
                } elseif (str_contains($key, 'alamat')) {
                    $data['address'] = $value;
                } elseif (str_contains($key, 'wa') || str_contains($key, 'whatsapp')) {
                    $data['whatsapp'] = $value;
                } elseif (str_contains($key, 'instagram') || str_contains($key, 'ig')) {
                    $data['instagram'] = $value;
                } elseif (str_contains($key, 'barang')) {
                    $data['items'] = $value;
                }
            }
        }

        // Validate: at least name must be present
        if (empty($data['name'])) {
            return null;
        }

        return $data;
    }

    /**
     * Auto-register customer from order form
     */
    private function autoRegisterCustomer(string $phone, array $orderData): Customer
    {
        try {
            $customer = Customer::create([
                'name' => $orderData['name'],
                'phone' => $phone,
                'address' => $orderData['address'] ?? null,
                'email' => $orderData['email'] ?? null,
                'projects_id' => 1, // Default project/branch
                'created_by' => null, // System auto-register
                'behavior' => $this->buildCustomerNote($orderData),
            ]);

            Log::info('Customer Auto-Registered from WhatsApp', [
                'customer_id' => $customer->id,
                'phone' => $phone,
                'name' => $customer->name,
            ]);

            return $customer;
        } catch (\Exception $e) {
            Log::error('Failed to auto-register customer', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'order_data' => $orderData,
            ]);

            throw $e;
        }
    }

    /**
     * Build customer note from order data
     */
    private function buildCustomerNote(array $orderData): string
    {
        $notes = [];

        if (!empty($orderData['instagram'])) {
            $notes[] = "Instagram: {$orderData['instagram']}";
        }

        if (!empty($orderData['whatsapp'])) {
            $notes[] = "WhatsApp Form: {$orderData['whatsapp']}";
        }

        if (!empty($orderData['items'])) {
            $notes[] = "Barang yang diminta: {$orderData['items']}";
        }

        $notes[] = "Sumber: WhatsApp Auto-Register";
        $notes[] = "Tanggal: " . date('Y-m-d H:i:s');

        return implode("\n", $notes);
    }

    /**
     * Auto-create order from customer form
     */
    private function autoCreateOrder(Customer $customer, array $orderData): Order
    {
        try {
            // Generate order code
            $orderCode = $this->generateOrderCode();

            // Build order note
            $note = "Order dari WhatsApp\n";
            if (!empty($orderData['items'])) {
                $note .= "Barang: {$orderData['items']}\n";
            }
            if (!empty($orderData['instagram'])) {
                $note .= "Instagram: {$orderData['instagram']}\n";
            }
            $note .= "Jam operasional kurir: 14.00-17.00\n";
            $note .= "Pembayaran wajib di awal (transaksi < Rp 500.000)";

            $order = Order::create([
                'customers_id' => $customer->id,
                'code' => $orderCode,
                'date' => time(),
                'total_price' => 0, // Will be updated by admin
                'total_discount' => 0,
                'note' => $note,
                'status' => 0, // Pending - waiting for admin confirmation
                'created_by' => null, // System auto-create
                'projects_id' => $customer->projects_id ?? 1,
            ]);

            Log::info('Order Auto-Created from WhatsApp', [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ]);

            return $order;
        } catch (\Exception $e) {
            Log::error('Failed to auto-create order', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'order_data' => $orderData,
            ]);

            throw $e;
        }
    }

    /**
     * Generate unique order code
     * Format: INV{YYYYMM}{0001}
     * Example: INV2026030001
     */
    private function generateOrderCode(): string
    {
        $prefix = 'INV';
        $yearMonth = date('Ym'); // YYYYMM format

        // Get last order code for this month
        $lastOrder = Order::where('code', 'LIKE', "{$prefix}{$yearMonth}%")
            ->orderBy('code', 'DESC')
            ->first();

        if ($lastOrder) {
            // Extract last 4 digits
            $lastNumber = (int) substr($lastOrder->code, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . $yearMonth . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
