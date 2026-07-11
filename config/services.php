<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WhatsApp via Wablas (hosted WhatsApp gateway)
    'wablas' => [
        'enabled' => env('WABLAS_ENABLED', false),
        'url' => env('WABLAS_URL', ''),           // e.g. https://bdg.wablas.com
        'token' => env('WABLAS_TOKEN', ''),
        'secret' => env('WABLAS_SECRET', ''),     // secure mode: Authorization = token.secret
        // Optional shared secret to validate incoming webhooks (matched against a
        // `secret` field/query param); leave empty to skip verification.
        'webhook_secret' => env('WABLAS_WEBHOOK_SECRET', ''),
    ],

    'fcm' => [
        'enabled' => env('FCM_ENABLED', false),
        'url' => env('FCM_URL', 'https://fcm.googleapis.com/fcm/send'),
        'server_key' => env('FCM_SERVER_KEY', ''),
    ],

];
