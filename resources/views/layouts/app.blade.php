<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <x-brand-head />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans">
        <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-surface focus:px-4 focus:py-2">{{ __('Skip to content') }}</a>

        <div class="min-h-screen">
            @include('layouts.navigation')

            @isset($header)
                <header class="mx-auto max-w-6xl px-4 pt-8 sm:px-6 lg:px-8">
                    {{ $header }}
                </header>
            @endisset

            <main id="content">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
