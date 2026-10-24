<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex">
        <meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#090d18" media="(prefers-color-scheme: dark)">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans">
        <div class="relative flex min-h-screen flex-col items-center overflow-hidden px-4 py-8 sm:py-14">
            {{-- The motif, cropped behind the page: the booking card sits on a time slot. --}}
            <div class="motif -top-40 left-1/2 h-80 w-[56rem] -translate-x-1/2 bg-primary/[0.07]" aria-hidden="true"></div>

            <main class="relative w-full max-w-5xl">
                {{ $slot }}
            </main>

            <footer class="relative mt-10">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs text-ink-3 transition-colors hover:bg-surface hover:text-ink-2">
                    {{ __('Scheduling by') }}
                    <span class="font-semibold">{{ config('app.name') }}</span>
                </a>
            </footer>
        </div>
    </body>
</html>
