<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manajemen koneksi WhatsApp (WAHA) untuk halaman pengaturan di admin panel.
 * Semua endpoint mem-proxy WAHA sehingga frontend tak perlu base URL / API key.
 */
class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppService $wa) {}

    // GET /api/whatsapp/status
    public function status(): JsonResponse
    {
        $config = $this->wa->configSummary();
        $info = $this->wa->getSessionInfo();
        $status = $info['status'] ?? null;

        return response()->json(array_merge($config, [
            'ok' => $info['ok'] ?? false,
            'reason' => $info['reason'] ?? null,
            'message' => $info['message'] ?? null,
            'status' => $status,
            'connected' => $status === 'WORKING',
            'needs_scan' => $status === 'SCAN_QR_CODE',
            'me' => $info['me'] ?? null,
        ]));
    }

    // GET /api/whatsapp/qr
    public function qr(): JsonResponse
    {
        return response()->json($this->wa->getQr());
    }

    // GET /api/whatsapp/settings — current WAHA config (API key never returned).
    public function settings(): JsonResponse
    {
        $config = $this->wa->configSummary();
        $apiKeySet = ! empty(Setting::read('waha_api_key', config('services.waha.api_key', '')));

        return response()->json([
            'enabled' => $config['enabled'],
            'base_url' => $config['base_url'],
            'session' => $config['session'],
            'api_key_set' => $apiKeySet,
        ]);
    }

    // PUT /api/whatsapp/settings — persist config to DB (overrides .env).
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'session' => ['nullable', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::write('waha_enabled', $validated['enabled']);

        if (! empty($validated['base_url'])) {
            Setting::write('waha_base_url', rtrim($validated['base_url'], '/'));
        }
        if (! empty($validated['session'])) {
            Setting::write('waha_session', $validated['session']);
        }
        // Only overwrite the API key when a new, non-empty value is provided —
        // leaving the field blank keeps the existing key.
        if (! empty($validated['api_key'])) {
            Setting::write('waha_api_key', $validated['api_key']);
        }

        return response()->json(['message' => 'Pengaturan WhatsApp berhasil disimpan.']);
    }

    // POST /api/whatsapp/start
    public function start(): JsonResponse
    {
        return response()->json($this->wa->startSession());
    }

    // POST /api/whatsapp/stop
    public function stop(): JsonResponse
    {
        return response()->json($this->wa->stopSession());
    }

    // POST /api/whatsapp/restart
    public function restart(): JsonResponse
    {
        return response()->json($this->wa->restartSession());
    }

    // POST /api/whatsapp/logout
    public function logout(): JsonResponse
    {
        return response()->json($this->wa->logoutSession());
    }
}
