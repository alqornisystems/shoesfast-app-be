<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Setting;
use App\Support\ServiceDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function index(Request $request): JsonResponse
    {
        $rows = Setting::whereIn('key', self::PUBLIC_KEYS)->pluck('value', 'key');

        // Tujuan pembayaran diambil dari CABANG pelanggan, bukan dari settings global.
        // Nomor WA-nya memang sudah per cabang; rekening yang global akan menyuruh
        // pelanggan Surabaya mentransfer ke rekening pusat lalu mengirim buktinya ke
        // nomor Surabaya.
        // Endpoint ini terbuka tanpa token — halaman daftar layanan memanggilnya
        // sebelum siapa pun masuk. Jadi cabangnya milik pelanggan kalau ada yang
        // masuk, dan cabang bawaan kalau tidak.
        $cabangId = $request->user()?->projects_id
            ?: (int) Setting::where('key', 'default_branch_id')->value('value');

        $cabang = $cabangId
            ? Project::withoutBranchScope()->find($cabangId)
            : null;

        return response()->json([
            'data' => $rows,
            // Tanggal tutup dikirim, bukan aturannya. Portal cukup mematikan tanggal
            // yang disebut di sini — kalau aturannya yang dikirim, kalender portal dan
            // penolakan server suatu saat akan berbeda pendapat, dan pelanggan yang
            // memilih tanggal yang tampak boleh akan ditolak tanpa tahu kenapa.
            'closed_dates' => ServiceDay::closedDates(strtotime('today')),
            // Tanggal bawaan untuk kolom jemput dan ambil: hari ini, atau hari buka
            // terdekat kalau hari ini tutup. Membiarkan kolomnya kosong berarti
            // pelanggan harus membuka kalender untuk menjawab pertanyaan yang
            // jawabannya hampir selalu "secepatnya".
            'default_date' => date('Y-m-d', ServiceDay::nextOpen(strtotime('today'))),
            // null di mana pun berarti tombol bayarnya tidak muncul. Itu perilaku yang
            // benar: lebih baik tidak ada tombol daripada mengarahkan orang ke nomor
            // rekening yang salah.
            'payment' => [
                'whatsapp' => $cabang?->whatsapp ?: null,
                'bank_name' => $cabang?->bank_name ?: null,
                'bank_account_number' => $cabang?->bank_account_number ?: null,
                'bank_account_name' => $cabang?->bank_account_name ?: null,
            ],
        ]);
    }
}
