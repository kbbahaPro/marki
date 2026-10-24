@php
    use App\Enums\LocationType;
@endphp

<x-public-layout :title="$host->name">
    <div class="mx-auto max-w-2xl">
        <div class="text-center">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-primary text-2xl font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($host->name, 0, 1)) }}</span>
            <h1 class="mt-4 text-3xl font-bold tracking-tight text-ink">{{ $host->name }}</h1>
            <p class="mt-2 text-ink-2">{{ __('Pick a meeting type to see available times.') }}</p>
        </div>

        @if ($eventTypes->isEmpty())
            <div class="card mt-10 p-8 text-center text-ink-2">{{ __('No meetings are available to book right now.') }}</div>
        @else
            <ul class="mt-10 space-y-3">
                @foreach ($eventTypes as $eventType)
                    <li>
                        <a href="{{ route('public.book', [$host->username, $eventType->slug]) }}"
                           class="card group flex items-center gap-5 p-5 transition-colors hover:border-primary/40 sm:p-6">
                            <span class="flex h-12 w-20 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary-ink tabular-nums">
                                {{ $eventType->duration_minutes }}<span class="ms-0.5 font-semibold">{{ __('min') }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-ink">{{ $eventType->title }}</span>
                                <span class="mt-0.5 flex items-center gap-1.5 text-sm text-ink-3">
                                    <x-icon :name="match ($eventType->location_type) { LocationType::GoogleMeet => 'video', LocationType::Phone => 'phone', LocationType::Custom => 'map-pin' }" class="h-4 w-4" />
                                    {{ $eventType->location_type->label() }}
                                </span>
                            </span>
                            <x-icon name="arrow-right" class="h-5 w-5 text-ink-3 transition-transform group-hover:translate-x-1 group-hover:text-primary-ink" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-public-layout>
