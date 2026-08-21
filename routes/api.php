<?php

use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\CatalogController as CustomerCatalogController;
use App\Http\Controllers\Api\Customer\ClaimController as CustomerClaimController;
use App\Http\Controllers\Api\Customer\MembershipController as CustomerMembershipController;
use App\Http\Controllers\Api\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Api\Customer\ProfileController as CustomerProfileController;
use App\Http\Controllers\Api\Customer\RewardController as CustomerRewardController;
use App\Http\Controllers\Api\Customer\SettingController as CustomerSettingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyNoteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExpenseOperationalController;
use App\Http\Controllers\Api\GuaranteeClaimController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PartnershipController;
use App\Http\Controllers\Api\PartnershipTreatmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\RedemptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RewardController as AdminRewardController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SendController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceHppController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\TreatmentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Otorisasi per jabatan
|--------------------------------------------------------------------------
| Menyembunyikan menu di sidebar tidak menahan siapa pun: sebelum ini seorang
| teknisi cukup mengetik /laporan-laba-rugi di address bar dan API tetap
| menyajikan datanya. Middleware `role:` di bawah adalah pagarnya yang
| sebenarnya, dan daftarnya sengaja dibuat cermin dari navGroups di
| shoesfast-app-fe/src/components/app-sidebar.tsx — kalau salah satu berubah,
| yang lain harus ikut, kalau tidak menu tampil tapi datanya 403.
|
| 'Admin Super' tidak punya bypass otomatis; ia disebut di tiap daftar. Nama
| role yang dipakai persis isi tabel `roles`: Admin Super, Admin, Teknisi,
| Kurir, HRD, Finance, Admin Sosmed, Admin Crm.
*/
// Public Routes (No Authentication Required)
Route::prefix('auth')->group(function () {
    // Rate-limit: maks 6 percobaan login per menit per IP
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
});

// Wablas incoming-message webhook (publik; opsional diverifikasi WABLAS_WEBHOOK_SECRET)
Route::post('webhook', [WebhookController::class, 'whatsapp']);

// Invoice publik (tanpa login) — dibuka customer dari tautan WhatsApp.
// Throttle supaya token 40 karakter tidak bisa digempur brute force.
Route::get('public/invoice/{token}', [PublicInvoiceController::class, 'show'])
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Portal pelanggan
|--------------------------------------------------------------------------
| Guard `customer` terpisah penuh dari guard staf. Token staf tidak berlaku
| di sini dan sebaliknya — pemisahnya ada di config/auth.php, bukan di sini.
*/
/*
| Captcha dipasang di SELURUH jalur ini, bukan hanya pendaftaran. `check-phone`
| justru yang paling menggoda bagi bot: ia menjawab nomor mana yang terdaftar,
| jadi tanpa penjaga ia menjadi alat menyapu basis pelanggan satu per satu.
|
| Kalau CAPTCHA_SECRET kosong, middleware-nya melewatkan semuanya — portal sudah
| hidup, dan rilis yang mengunci seluruh pelanggan sebelum kuncinya disetel jauh
| lebih merugikan daripada bot yang lolos.
*/
Route::prefix('customer/auth')->middleware('captcha')->group(function () {
    Route::post('check-phone', [CustomerAuthController::class, 'checkPhone'])->middleware('throttle:20,1');
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:10,1');
    // Pembuatan PIN dibatasi ketat: tanpa verifikasi kanal, ini satu-satunya
    // rem terhadap klaim akun borongan per IP.
    Route::post('set-pin', [CustomerAuthController::class, 'setPin'])->middleware('throttle:5,1');
    Route::post('register', [CustomerAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('forgot-pin', [CustomerAuthController::class, 'forgotPin'])->middleware('throttle:5,1');
});

// Katalog terbuka tanpa login supaya calon pelanggan bisa melihat harga
// sebelum memutuskan mendaftar.
Route::get('customer/services', [CustomerCatalogController::class, 'index'])
    ->middleware('throttle:60,1');

// Teks syarat gratis jemput dibaca portal sebelum pelanggan mengirim
// permintaan. Publik karena calon pelanggan boleh membacanya sebelum masuk.
Route::get('customer/settings', [CustomerSettingController::class, 'index'])
    ->middleware('throttle:60,1');

// Pelacakan kurir yang dibuka pelanggan dari tautan WhatsApp. Publik dan tanpa login:
// menuntut akun untuk pertanyaan "kurir saya di mana" berarti tidak ada yang memakainya.
// Penjaganya token acak 40 karakter + masa berlaku, bukan sesi. Throttle-nya longgar
// karena halaman memanggil ini berulang selama peta terbuka.
Route::get('lacak/{token}', [TrackingController::class, 'show'])
    ->middleware('throttle:120,1');

// Gerbang versi aplikasi mobile, dibaca saat splash. Publik dengan sengaja: kalau ia
// menuntut token, aplikasi yang kontraknya sudah tidak kompatibel harus berhasil login
// dulu sebelum boleh diberi tahu bahwa ia terlalu tua.
Route::get('app/version', [AppVersionController::class, 'show'])
    ->middleware('throttle:60,1');

Route::middleware('auth:customer')->prefix('customer')->group(function () {
    Route::get('auth/me', [CustomerAuthController::class, 'me']);
    Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
    Route::put('profile', [CustomerProfileController::class, 'update']);

    Route::get('orders', [CustomerOrderController::class, 'index']);
    Route::post('orders', [CustomerOrderController::class, 'store']);
    // Rute aksi khusus SEBELUM orders/{id}, kalau tidak 'invoice' tertangkap
    // sebagai {id} — aturan yang sudah berlaku di seluruh berkas ini.
    Route::get('orders/{id}/invoice', [CustomerOrderController::class, 'invoice']);
    Route::post('orders/{id}/items/{itemId}/claim', [CustomerClaimController::class, 'store']);
    Route::get('claims', [CustomerClaimController::class, 'index']);
    Route::get('orders/{id}', [CustomerOrderController::class, 'show']);

    Route::post('membership/join', [CustomerMembershipController::class, 'join']);
    Route::get('membership', [CustomerMembershipController::class, 'show']);

    Route::get('rewards', [CustomerRewardController::class, 'index']);
    Route::post('rewards/{id}/redeem', [CustomerRewardController::class, 'redeem']);
    Route::get('redemptions', [CustomerRewardController::class, 'history']);
});

// Protected
Route::middleware('auth:sanctum')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Terbuka untuk SEMUA jabatan
    |----------------------------------------------------------------------
    | Akun sendiri, dashboard, dan tiga urusan kepegawaian yang dipunyai
    | setiap karyawan apa pun jabatannya: absen, izin, catatan harian.
    */
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('switch-branch', [AuthController::class, 'switchBranch']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('change-password', [AuthController::class, 'changePassword']);

        // Token FCM perangkat. DELETE dipanggil saat logout supaya HP yang
        // dipinjamkan berhenti menerima notifikasi tugas milik orang lain.
        Route::post('device-token', [AuthController::class, 'registerDeviceToken']);
        Route::delete('device-token', [AuthController::class, 'deleteDeviceToken']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);

    // Absensi
    Route::get('attendances/today', [AttendanceController::class, 'today']);
    Route::post('attendances/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendances/clock-out', [AttendanceController::class, 'clockOut']);
    Route::get('attendances', [AttendanceController::class, 'index']);
    // Status per tanggal untuk kalender presensi — gabungan absensi, izin, dan kalender
    // libur. Sebelum apiResource mana pun tidak relevan di sini, tapi tetap ditaruh
    // sebelum rute ber-{id} agar 'daily-status' tidak tertangkap sebagai sebuah id.
    Route::get('attendances/daily-status', [AttendanceController::class, 'dailyStatus']);

    // Pengajuan izin — mengajukan dan membatalkan milik sendiri terbuka untuk semua;
    // menyetujui/menolak adalah wewenang atasan, dikunci di blok SDM di bawah.
    Route::get('absences', [AttendanceController::class, 'absences']);
    Route::post('absences', [AttendanceController::class, 'storeAbsence']);
    Route::delete('absences/{id}', [AttendanceController::class, 'deleteAbsence']);

    // Catatan harian
    Route::get('daily-notes/available-users', [DailyNoteController::class, 'availableUsers']);
    Route::get('daily-notes/search-users', [DailyNoteController::class, 'searchUsers']);
    Route::get('daily-notes/today', [DailyNoteController::class, 'today']);
    Route::put('daily-notes/{id}/toggle-status', [DailyNoteController::class, 'toggleStatus']);
    Route::apiResource('daily-notes', DailyNoteController::class);

    // Dibaca oleh halaman absensi, izin, dan catatan harian (kalender libur & titik cabang).
    // Hanya baca; yang menulis ada di blok Pengaturan Perusahaan.
    Route::get('holidays', [HolidayController::class, 'index']);
    Route::get('holidays/{holiday}', [HolidayController::class, 'show']);
    Route::get('projects', [ProjectController::class, 'index']);
    Route::get('projects/{project}', [ProjectController::class, 'show']);

    /*
    |----------------------------------------------------------------------
    | Operasional lapangan — Teknisi & Kurir
    |----------------------------------------------------------------------
    | Teknisi di lapangan juga merangkap mengantar, jadi keduanya memakai
    | Pengerjaan maupun Pengiriman. `treatments/assign` tidak ada di sini:
    | membagi pekerjaan adalah wewenang admin (menu Waiting List).
    */
    Route::middleware('role:Admin Super,Admin,Teknisi,Kurir')->group(function () {
        Route::get('treatments', [TreatmentController::class, 'index']);
        // Teknisi/kurir mengambil pekerjaan dari waiting list — langsung jadi miliknya.
        Route::post('treatments/claim', [TreatmentController::class, 'claim']);
        Route::put('treatments/{id}/status', [TreatmentController::class, 'updateStatus']);
        // Teknisi menekan "mulai kerjakan". Menstempel started_at, bukan date_start —
        // yang itu jadwal rencana yang dipakai mengurutkan antrean dan menghitung progress.
        Route::post('treatments/{id}/start', [TreatmentController::class, 'start']);
        Route::put('treatments/{id}/update', [TreatmentController::class, 'update']);
        Route::get('treatments/available-technicians', [TreatmentController::class, 'getAvailableTechnicians']);

        // Sends (Delivery & Pickup) - custom routes before apiResource
        Route::get('sends/pickup-waiting-list', [SendController::class, 'pickupWaitingList']);
        Route::get('sends/delivery-waiting-list', [SendController::class, 'deliveryWaitingList']);
        Route::get('sends/in-progress', [SendController::class, 'inProgress']);
        Route::get('sends/history', [SendController::class, 'history']);
        Route::get('sends/available-pickup-orders', [SendController::class, 'getAvailablePickupOrders']);
        Route::get('sends/available-delivery-items', [SendController::class, 'getAvailableDeliveryItems']);
        Route::get('sends/available-couriers', [SendController::class, 'getAvailableCouriers']);
        Route::post('sends/mark-completed', [SendController::class, 'markAsCompleted']);
        // Ringkasan hasil kerja kurir hari itu. Sebelum apiResource DAN sebelum rute
        // ber-{id}, kalau tidak 'summary' tertangkap sebagai sebuah id.
        Route::get('sends/summary', [SendController::class, 'summary']);
        // Rincian barang untuk kurir: pengerjaan, riwayat, kelengkapan. Tanpa harga.
        Route::get('sends/{id}/detail', [SendController::class, 'detail']);
        // Jemput ulang: dari satu pengiriman yang sudah ada dibuatkan pesanan baru sekalian
        // tugas jemputnya. Sebelum apiResource, sesuai aturan berkas ini.
        Route::post('sends/{id}/reorder', [SendController::class, 'reorder']);

        /*
        | Alur tugas lapangan. Sebelum ini satu-satunya akhir sebuah tugas adalah
        | "selesai": uang yang ditagih kurir tidak punya jejak, kegagalan tidak punya
        | jalur, dan tidak ada bukti serah terima untuk menengahi sengketa.
        */
        Route::post('sends/{id}/payment', [SendController::class, 'recordPayment']);
        Route::post('sends/{id}/proof', [SendController::class, 'storeProof']);
        Route::post('sends/{id}/failed', [SendController::class, 'markFailed']);
        Route::post('sends/{id}/start', [SendController::class, 'start']);
        // Aplikasi kurir mengirim posisinya selama tugas berjalan.
        Route::post('sends/{id}/location', [SendController::class, 'recordLocation']);
        // Ambil satu tugas dari antrean jemput/antar — padanan treatments/claim.
        Route::post('sends/{id}/claim', [SendController::class, 'claimTask']);

        Route::apiResource('sends', SendController::class);

        // Barang pesanan untuk staf lapangan: kurir mencatat dan memeriksa barang di lokasi
        // penjemputan. Rutenya dibuka, muatannya tidak — OrderController memangkas harga untuk
        // non-admin, baik saat membaca (getItems) maupun saat menyimpan (saveItem mengambil
        // harga dari master layanan, bukan dari perangkat lapangan).
        Route::get('orders/{orderId}/items', [OrderController::class, 'getItems']);
        Route::post('orders/{orderId}/items', [OrderController::class, 'saveItem']);
        Route::delete('orders/{orderId}/items/{itemId}', [OrderController::class, 'removeItem']);

        // Daftar layanan untuk memilih pengerjaan barang tadi. Menimpa index milik
        // apiResource('services') di blok admin, yang karena itu dideklarasikan ->except(['index']).
        Route::get('services', [ServiceController::class, 'index']);
    });

    /*
    |----------------------------------------------------------------------
    | Operasional kantor — Admin
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin Super,Admin')->group(function () {
        Route::post('treatments/assign', [TreatmentController::class, 'assignToUser']);
        // Selesai paksa melewati QC, jadi bukan wewenang teknisi.
        Route::post('treatments/force-complete', [TreatmentController::class, 'forceComplete']);

        // Orders - custom routes before apiResource
        Route::get('orders/search/customers', [OrderController::class, 'searchCustomers']);
        Route::get('orders/search/services', [OrderController::class, 'searchServices']);
        Route::get('orders/available-pickup', [OrderController::class, 'getAvailablePickupOrders']);
        Route::post('orders/{id}/invoice-link', [OrderController::class, 'invoiceLink']);
        Route::apiResource('orders', OrderController::class);

        // index-nya sudah dideklarasikan di blok lapangan supaya teknisi/kurir bisa memilih
        // layanan; menulis layanan tetap wewenang admin.
        Route::apiResource('services', ServiceController::class)->except(['index']);

        // Service HPP
        Route::prefix('services/{serviceId}/hpp')->group(function () {
            Route::get('/', [ServiceHppController::class, 'index']);
            Route::post('/', [ServiceHppController::class, 'store']);
            Route::post('/batch', [ServiceHppController::class, 'batchSave']);
            Route::put('/{id}', [ServiceHppController::class, 'update']);
            Route::delete('/{id}', [ServiceHppController::class, 'destroy']);
        });

        // Mitra kerja
        Route::apiResource('partnerships', PartnershipController::class);
        Route::prefix('partnerships/{partnershipId}/treatments')->group(function () {
            Route::get('/', [PartnershipTreatmentController::class, 'myTreatments']);
            Route::get('/statistics', [PartnershipTreatmentController::class, 'statistics']);
            Route::get('/{treatmentId}', [PartnershipTreatmentController::class, 'show']);
            Route::put('/{treatmentId}/status', [PartnershipTreatmentController::class, 'updateStatus']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | Pelanggan & CRM
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin Super,Admin,Admin Crm,Admin Sosmed')->group(function () {
        // Sebelum apiResource, kalau tidak 'reset-pin' tertangkap sebagai {id}.
        // Hanya Admin Super dan Admin: reset PIN mengembalikan akun pelanggan
        // ke tangan pemiliknya, jadi bukan wewenang CRM atau Sosmed.
        Route::post('customers/{id}/reset-pin', [CustomerController::class, 'resetPin'])
            ->middleware('role:Admin Super,Admin');

        Route::apiResource('customers', CustomerController::class);

        Route::prefix('broadcasts')->group(function () {
            Route::get('templates', [BroadcastController::class, 'templates']);
            Route::get('templates/{id}', [BroadcastController::class, 'showTemplate']);
            Route::post('templates', [BroadcastController::class, 'storeTemplate']);
            Route::put('templates/{id}', [BroadcastController::class, 'updateTemplate']);
            Route::delete('templates/{id}', [BroadcastController::class, 'destroyTemplate']);

            Route::post('send', [BroadcastController::class, 'send']);
            Route::get('recipients', [BroadcastController::class, 'recipients']);
            Route::post('preview', [BroadcastController::class, 'preview']);

            Route::get('/', [BroadcastController::class, 'index']);
            Route::get('{id}', [BroadcastController::class, 'show']);
            Route::delete('{id}', [BroadcastController::class, 'destroy']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | Keuangan
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin Super,Admin,Finance')->group(function () {
        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store']);
        Route::get('payments/order/{orderId}', [PaymentController::class, 'getByOrder']);
        Route::get('payments/unpaid-orders', [PaymentController::class, 'getUnpaidOrders']);
        Route::delete('payments/{id}', [PaymentController::class, 'destroy']);

        Route::apiResource('expenses', ExpenseController::class);
        Route::apiResource('expense-operationals', ExpenseOperationalController::class);
    });

    /*
    |----------------------------------------------------------------------
    | Sumber daya manusia
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin Super,Admin,HRD')->group(function () {
        // Sebelum apiResource, kalau tidak 'reset-password' tertangkap sebagai {user}.
        // Staf yang lupa kata sandinya tidak bisa absen sama sekali, dan absen adalah hal
        // pertama yang dilakukannya pagi hari — jadi jalur ini menahan orang bekerja.
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::apiResource('users', UserController::class);
        Route::apiResource('roles', RoleController::class);
    });

    // Persetujuan izin — HANYA Admin dan Admin Super. HRD boleh mengelola data karyawan dan
    // melihat pengajuannya, tapi memutuskan disetujui atau ditolak bukan wewenangnya.
    // Tombol Setujui/Tolak di halaman /izin memakai daftar yang sama; kalau salah satu diubah,
    // yang lain harus ikut, kalau tidak tombolnya tampil lalu ditolak 403.
    Route::middleware('role:Admin Super,Admin')->group(function () {
        Route::put('absences/{id}/approve', [AttendanceController::class, 'approveAbsence']);
        Route::put('absences/{id}/reject', [AttendanceController::class, 'rejectAbsence']);
    });

    /*
    |----------------------------------------------------------------------
    | Program member: katalog hadiah, penukaran poin, klaim garansi
    |----------------------------------------------------------------------
    | Sisi admin dari apa yang dihasilkan portal pelanggan. Daftar peran di
    | sini harus cermin navGroups di app-sidebar.tsx — kalau salah satu
    | berubah, menu tampil tapi datanya 403.
    */
    Route::middleware('role:Admin Super,Admin')->group(function () {
        Route::apiResource('rewards', AdminRewardController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('redemptions', [RedemptionController::class, 'index']);
        Route::post('redemptions/{id}/complete', [RedemptionController::class, 'complete']);

        Route::get('guarantee-claims', [GuaranteeClaimController::class, 'index']);
        Route::put('guarantee-claims/{id}', [GuaranteeClaimController::class, 'update']);
    });

    /*
    |----------------------------------------------------------------------
    | Pengaturan perusahaan
    |----------------------------------------------------------------------
    | Cabang, kalender libur, dan koneksi WhatsApp. Route baca untuk holidays
    | dan projects sudah dibuka untuk semua di blok paling atas; di sini hanya
    | yang mengubah data.
    */
    Route::middleware('role:Admin Super,Admin')->group(function () {
        Route::post('projects', [ProjectController::class, 'store']);
        Route::put('projects/{project}', [ProjectController::class, 'update']);
        Route::patch('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);

        Route::post('holidays', [HolidayController::class, 'store']);
        Route::put('holidays/{holiday}', [HolidayController::class, 'update']);
        Route::patch('holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy']);

        Route::prefix('whatsapp')->group(function () {
            Route::get('status', [WhatsAppController::class, 'status']);
            Route::get('qr', [WhatsAppController::class, 'qr']);
            Route::get('settings', [WhatsAppController::class, 'settings']);
            Route::put('settings', [WhatsAppController::class, 'updateSettings']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | Laporan
    |----------------------------------------------------------------------
    | Dipecah mengikuti pengelompokan di sidebar: angka keuangan tidak boleh
    | terbuka untuk HRD atau tim sosmed hanya karena sama-sama "laporan".
    */
    Route::prefix('reports')->group(function () {
        Route::middleware('role:Admin Super,Admin,Finance')->group(function () {
            Route::get('sales', [ReportController::class, 'sales']);
            Route::get('payments', [ReportController::class, 'payments']);
            Route::get('receivables', [ReportController::class, 'receivables']);
            Route::get('orders', [ReportController::class, 'orders']);
            Route::get('expenses', [ReportController::class, 'expenses']);
            Route::get('hpp', [ReportController::class, 'hpp']);
            Route::get('profit-loss', [ReportController::class, 'profitLoss']);
            Route::get('cash-flow', [ReportController::class, 'cashFlow']);
        });

        Route::middleware('role:Admin Super,Admin')->group(function () {
            Route::get('treatments', [ReportController::class, 'treatments']);
            Route::get('performance', [ReportController::class, 'performance']);
        });

        Route::middleware('role:Admin Super,Admin,Admin Crm,Admin Sosmed')->group(function () {
            Route::get('customers', [ReportController::class, 'customers']);
            Route::get('top-services', [ReportController::class, 'topServices']);
            Route::get('google-ads', [ReportController::class, 'googleAds']);
            Route::get('meta-ads', [ReportController::class, 'metaAds']);
        });

        Route::middleware('role:Admin Super,Admin,HRD')->group(function () {
            Route::get('attendance', [ReportController::class, 'attendance']);
            Route::get('daily-notes', [ReportController::class, 'dailyNotes']);
            Route::get('daily-notes-matrix', [ReportController::class, 'dailyNotesMatrix']);
        });
    });
});
