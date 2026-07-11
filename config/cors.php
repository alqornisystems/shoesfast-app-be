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

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', ''))
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
