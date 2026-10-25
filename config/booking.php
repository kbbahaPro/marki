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
        // Text beside the mark; falls back to APP_NAME.
        'wordmark' => env('BRAND_WORDMARK'),
        'tagline' => env('BRAND_TAGLINE', 'Mark that time.'),
        // Hex colours. "primary" drives actions and links; "marker" highlights
        // the chosen thing (selected date, your time). Both are contrast-checked.
        'primary' => env('BRAND_PRIMARY', '#1D5FAF'),
        'marker' => env('BRAND_MARKER', '#F4A93C'),
        // Optional replacements for the built-in Marki mark (absolute URLs).
        'logo_url' => env('BRAND_LOGO_URL'),
        'favicon_url' => env('BRAND_FAVICON_URL'),
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

    // Confirmed bookings become "completed" this long after they end, unless
    // a host marked them first. Hosts can still switch to "no-show" later.
    'auto_complete_after_minutes' => (int) env('BOOKING_AUTO_COMPLETE_AFTER_MINUTES', 60),

    'invitations' => [
        'expire_days' => (int) env('INVITATION_EXPIRE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Only honoured when APP_ENV=testing: pauses inside the booking transaction
    | so the concurrency test can force two requests to overlap.
    |
    */

    'testing' => [
        'lock_pause_ms' => (int) env('BOOKING_TEST_LOCK_PAUSE_MS', 0),
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
