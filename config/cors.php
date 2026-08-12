<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Origin diambil dari FRONTEND_URL (dipisah koma) supaya spec-valid saat
| supports_credentials = true (Access-Control-Allow-Origin tidak boleh "*"
| bila credentials diizinkan). Fallback ke "*" hanya bila FRONTEND_URL kosong,
| dan dalam kasus itu credentials dimatikan agar tetap valid.
|
*/

/*
| Domain produksi milik sendiri, selalu ikut diizinkan.
|
| FRONTEND_URL tinggal di .env server yang dikelola manual dan tidak masuk git,
| jadi ia selalu tertinggal setiap kali ada frontend baru — portal pelanggan
| rilis dengan CORS memblokirnya, dan gejalanya paling jahat: tidak ada galat
| di log server, hanya halaman kosong di HP pelanggan.
|
| Ini bukan pelonggaran keamanan: yang ditambahkan hanya domain milik sendiri,
| bukan "*". Origin dari .env tetap dihormati dan tetap yang PERTAMA, karena
| config/app.php mengambil entri pertama FRONTEND_URL sebagai frontend_url
| untuk tautan invoice.
*/
$bawaan = [
    'https://app.shoesfast.id',
    'https://customer.shoesfast.id',
];

$origins = array_values(array_unique(array_merge(
    array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', ''))
    ))),
    $bawaan
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Valid hanya jika origin eksplisit; kalau fallback "*", matikan credentials.
    'supports_credentials' => (bool) $origins,

];
