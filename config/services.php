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

    /*
    |--------------------------------------------------------------------------
    | Socialite Providers
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', '/auth/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'onesignal' => [
        'app_id' => env('ONESIGNAL_APP_ID', ''),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY', ''),
    ],

    // WisperBot's own ad measurement (Meta dataset). Super Admin → Integrations
    // overrides these; they are a fallback for deployments that have not saved it.
    'meta_pixel' => [
        'pixel_id' => env('META_PIXEL_ID', ''),
        'access_token' => env('META_CAPI_ACCESS_TOKEN', ''),
        'test_event_code' => env('META_CAPI_TEST_EVENT_CODE', ''),
        'domain_verification' => env('META_DOMAIN_VERIFICATION', ''),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', ''),
        'api_key' => env('QDRANT_API_KEY', ''),
    ],

    'fast2sms' => [
        'webhook_secret' => env('FAST2SMS_WEBHOOK_SECRET'),
    ],

    'alaris' => [
        'webhook_secret' => env('ALARIS_WEBHOOK_SECRET'),
    ],

];
