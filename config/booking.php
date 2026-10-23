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

    // Weekly hours given to every new member (weekdays: 0 = Sunday … 6 = Saturday).
    'default_working_hours' => [
        'weekdays' => [1, 2, 3, 4, 5],
        'start' => '09:00',
        'end' => '17:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event types
    |--------------------------------------------------------------------------
    */

    // Reminder offsets (minutes before the meeting) hosts can pick from.
    'reminder_options' => [10080, 1440, 120, 60, 30, 15],

    'event_type_defaults' => [
        'duration_minutes' => 30,
        'min_notice_minutes' => 240,
        'max_days_ahead' => 60,
        'reminder_offsets' => [1440, 60],
    ],

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
        'admin', 'api', 'app', 'assets', 'auth', 'availability', 'book', 'booking', 'bookings',
        'build', 'calendar', 'confirm-password', 'dashboard', 'email',
        'event-types', 'forgot-password', 'google', 'help', 'invitations',
        'login', 'logout', 'manage', 'oauth', 'password', 'profile',
        'register', 'reset-password', 'settings', 'storage', 'support',
        'team', 'up', 'verify-email', 'www',
    ],

];
