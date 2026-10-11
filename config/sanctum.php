<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Masa Berlaku Token (menit) — override per-token
    |--------------------------------------------------------------------------
    |
    | 'expiration' di atas sengaja null agar tidak menimpa "expires_at" per
    | token. AuthController menetapkan expiry per token: TTL normal vs TTL
    | "remember me". Diperbarui saat client memanggil endpoint refresh.
    |
    | Angkanya DITULIS DI SINI, tanpa env(). Dulu keduanya env() — dan .env
    | produksi dikelola manual di server, tidak ikut git, tidak ikut deploy FTP.
    | Artinya satu baris basi di berkas yang tidak bisa dilihat siapa pun dari
    | repo ini bisa diam-diam mengalahkan angka yang sudah disepakati, dan
    | gejalanya cuma "kok saya logout terus" tanpa satu pun galat di log.
    |
    | Umur sesi juga bukan beda antar lingkungan: ia janji produk yang tertulis
    | di halaman login ("Ingat saya selama 30 hari"). Satu tempat untuk
    | mengubahnya, dan tempat itu ikut terkirim sendiri saat deploy.
    |
    | Pasangannya ada di tests/Feature/SesiTokenTest.php — mengubah angka di
    | sini tanpa mengubah tulisan di halaman login membuat test itu gagal.
    |
    */

    'token_ttl' => 1440,          // 1 hari
    'remember_token_ttl' => 43200, // 30 hari, sesuai tulisan di halaman login

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
