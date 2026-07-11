<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manajemen koneksi WhatsApp (Wablas) untuk halaman pengaturan di admin panel.
 * Koneksi (scan QR) dikelola lewat halaman scan Wablas yang di-embed di frontend.
 */
class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppService $wa) {}

    // GET /api/whatsapp/status
    public function status(): JsonResponse
    {
        $config = $this->wa->configSummary();
        $info = $this->wa->getDeviceStatus();

        return response()->json(array_merge($config, [
            'ok' => $info['ok'] ?? false,
            'reason' => $info['reason'] ?? null,
            'message' => $info['message'] ?? null,
            'status' => $info['status'] ?? null,
            'connected' => $info['connected'] ?? false,
        ]));
    }

    // GET /api/whatsapp/qr — URL halaman scan Wablas untuk di-embed (iframe).
    public function qr(): JsonResponse
    {
        $url = $this->wa->getScanUrl();

        return response()->json([
            'ok' => (bool) $url,
            'scan_url' => $url,
            'message' => $url ? null : 'Wablas belum dikonfigurasi (url / token kosong).',
        ]);
    }

    // GET /api/whatsapp/settings — konfigurasi Wablas (token/secret tidak dikembalikan).
    public function settings(): JsonResponse
    {
        $config = $this->wa->configSummary();

        return response()->json([
            'driver' => 'wablas',
            'enabled' => $config['enabled'],
            'base_url' => $config['base_url'],
            'token_set' => !empty(Setting::read('wablas_token', config('services.wablas.token', ''))),
            'secret_set' => !empty(Setting::read('wablas_secret', config('services.wablas.secret', ''))),
        ]);
    }

    // PUT /api/whatsapp/settings — simpan konfigurasi Wablas ke DB (override .env).
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'token' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::write('wablas_enabled', $validated['enabled']);

        if (!empty($validated['base_url'])) {
            Setting::write('wablas_url', rtrim($validated['base_url'], '/'));
        }
        // Hanya timpa token/secret bila diisi — dibiarkan kosong = pakai yang lama.
        if (!empty($validated['token'])) {
            Setting::write('wablas_token', $validated['token']);
        }
        if (!empty($validated['secret'])) {
            Setting::write('wablas_secret', $validated['secret']);
        }

        return response()->json(['message' => 'Pengaturan WhatsApp berhasil disimpan.']);
    }
}
