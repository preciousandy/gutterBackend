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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'quidax' => [
        'secret_key' => env('QUIDAX_SECRET_KEY'),
        'public_key' => env('QUIDAX_PUBLIC_KEY'),
        'base_url' => env('QUIDAX_BASE_URL', 'https://www.quidax.com/api/v1'),
        'webhook_secret' => env('QUIDAX_WEBHOOK_SECRET'),
    ],

    'bigisub' => [
        'base_url' => env('BIGISUB_BASE_URL', 'https://api.bigisub.ng/'),
        'api_key' => env('BIGISUB_API_KEY', 'f89dca9d3c0814c375320ddfda09d91ce0bb10e5'),
        'pin' => env('BIGISUB_PIN', ''),
        'default_provider' => env('VAS_DEFAULT_PROVIDER', 'bigisub'),
    ],

    'vtpass' => [
        'base_url' => env('VTPASS_BASE_URL', 'https://vtpass.com/api/'),
        'api_key' => env('VTPASS_API_KEY'),
        'public_key' => env('VTPASS_PUBLIC_KEY'),
        'secret_key' => env('VTPASS_SECRET_KEY'),
    ],

    'palmpay' => [
        'base_url' => env('PALMPAY_BASE_URL', 'https://open-gw-prod.palmpay-inc.com'),
        'app_id' => env('PALMPAY_APP_ID'),
        'private_key' => env('PALMPAY_MERCHANT_PRIVATE_KEY_BASE64'),
        'public_key' => env('PALMPAY_PLATFORM_PUBLIC_KEY'),
        'country_code' => env('PALMPAY_COUNTRY_CODE', 'NG'),
        'webhook_url' => env('PALMPAY_WEBHOOK_URL', 'https://cloudnet.icu/api/v1/webhooks/palmpay'),
    ],

    'prembly' => [
        'base_url' => env('PREMBLY_BASE_URL', 'https://api.prembly.com'),
        'api_key' => env('PREMBLY_API_KEY', 'live_sk_bed5e2a3a4d04e0ebeafcb20cbe9a68a'),
        'app_id' => env('PREMBLY_APP_ID', 'live_pk_3cf9b264a9a0469fa2b32e74733dce33'),
    ],

];
