<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | The product name comes from APP_NAME. Everything else that identifies a
    | particular deployment lives here so the code stays brand-neutral.
    |
    */

    'brand' => [
        'logo_url' => env('BRAND_LOGO_URL'),
        'support_email' => env('BRAND_SUPPORT_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team members
    |--------------------------------------------------------------------------
    */

    'default_timezone' => env('DEFAULT_HOST_TIMEZONE', 'UTC'),

    'invitations' => [
        'expire_days' => (int) env('INVITATION_EXPIRE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved usernames
    |--------------------------------------------------------------------------
    |
    | Public booking pages live at /{username}/{event-slug}, so usernames
    | must never shadow application routes.
    |
    */

    'reserved_usernames' => [
        'admin', 'api', 'app', 'assets', 'auth', 'book', 'booking', 'bookings',
        'build', 'calendar', 'confirm-password', 'dashboard', 'email',
        'event-types', 'forgot-password', 'google', 'help', 'invitations',
        'login', 'logout', 'manage', 'oauth', 'password', 'profile',
        'register', 'reset-password', 'settings', 'storage', 'support',
        'team', 'up', 'verify-email', 'www',
    ],

];
