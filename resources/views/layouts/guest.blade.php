<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans">
        <div class="relative flex min-h-screen overflow-hidden">
            {{-- Form side --}}
            <div class="relative z-10 flex w-full flex-col px-6 py-8 sm:px-12 lg:w-1/2 lg:px-20">
                <a href="/" class="self-start rounded-full">
                    <x-application-logo />
                </a>

                <main class="my-auto w-full max-w-sm py-12">
                    {{ $slot }}
                </main>

                <p class="text-xs text-ink-3">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
            </div>

            {{-- The motif at its largest: one giant time-slot pill, cropped by the viewport. --}}
            <div class="relative hidden flex-1 lg:block" aria-hidden="true">
                <div class="motif -right-40 top-[14%] h-[44vh] w-[62vw]"></div>
                <div class="motif -right-24 top-[62%] h-[22vh] w-[30vw] bg-primary/15"></div>
                <div class="absolute bottom-[12%] left-0 max-w-xs">
                    <div class="flex items-center gap-3">
                        @foreach (['09:00', '09:30', '10:00'] as $i => $time)
                            <span class="rounded-full border px-4 py-2 text-sm font-semibold tabular-nums {{ $i === 1 ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink-2' }}">{{ $time }}</span>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm text-ink-2">{{ __('Share a link. Let people pick a time. Everything else is taken care of.') }}</p>
                </div>
            </div>
        </div>
    </body>
</html>
