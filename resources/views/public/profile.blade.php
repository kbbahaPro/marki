<x-public-layout :title="$host->name">
    <div class="mx-auto max-w-2xl">
        <div class="text-center">
            <x-avatar :user="$host" size="xl" decorative class="mx-auto ring-4 ring-surface" loading="eager" />
            <h1 class="mt-4 text-3xl font-bold tracking-tight text-ink">{{ $host->name }}</h1>
            @if ($host->headline())
                <p class="mt-1 font-medium text-ink-2">{{ $host->headline() }}</p>
            @endif
            <p class="mt-3 text-sm text-ink-3">{{ __('Pick a meeting type to see available times.') }}</p>
        </div>

        @if ($eventTypes->isEmpty())
            <div class="card mt-10 p-8 text-center text-ink-2">{{ __('No meetings are available to book right now.') }}</div>
        @else
            @include('public.partials.event-type-list')
        @endif
    </div>
</x-public-layout>
