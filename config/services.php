<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // How long free/busy answers are reused before asking Google again.
        'freebusy_cache_seconds' => (int) env('GOOGLE_FREEBUSY_CACHE_SECONDS', 180),
    ],

    // Optional CRM integration (see DEPLOYMENT.md → "Connect a CRM"). Booking
    // webhooks are sent only when the install id, URL and secret are all set;
    // the "existing customer" lookup only when the API URL and key are set.
    'crm' => [
        'install_id' => env('MARKI_INSTALL_ID'),
        'webhook_url' => env('CRM_WEBHOOK_URL'),
        'webhook_secret' => env('CRM_WEBHOOK_SECRET'),
        'api_url' => env('CRM_API_URL'),
        'api_key' => env('CRM_API_KEY'),
        'lookup_cache_seconds' => (int) env('CRM_LOOKUP_CACHE_SECONDS', 600),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
