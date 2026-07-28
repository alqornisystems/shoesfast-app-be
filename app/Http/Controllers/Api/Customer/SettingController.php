<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * Daftar putih, bukan daftar hitam. Tabel settings juga menyimpan
     * kredensial WAHA (waha_api_key, waha_base_url); mengembalikan seluruh
     * isi tabel lalu membuang yang rahasia akan bocor begitu ada kunci baru
     * yang lupa dimasukkan daftar buang.
     */
    private const PUBLIC_KEYS = ['free_pickup_terms', 'free_pickup_radius_km'];

    // GET /api/customer/settings
    public function index(): JsonResponse
    {
        $rows = Setting::whereIn('key', self::PUBLIC_KEYS)->pluck('value', 'key');

        return response()->json(['data' => $rows]);
    }
}
