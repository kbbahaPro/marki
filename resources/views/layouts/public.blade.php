<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased bg-gray-100">
        <div class="min-h-screen flex flex-col items-center px-4 py-8 sm:py-12">
            <main class="w-full max-w-4xl">
                {{ $slot }}
            </main>

            <footer class="mt-8 flex items-center gap-2 text-xs text-gray-400">
                @if (config('booking.brand.logo_url'))
                    <img src="{{ config('booking.brand.logo_url') }}" alt="" class="h-4 w-auto">
                @endif
                <span>{{ config('app.name') }}</span>
            </footer>
        </div>
    </body>
</html>
