@php
    use App\Enums\LocationType;
@endphp

<x-app-layout :title="__('Event types')">
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="page-title">{{ __('Event types') }}</h1>
                <p class="mt-1 muted">{{ __('Each one gets its own booking link.') }}</p>
            </div>
            <a href="{{ route('event-types.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> {{ __('New event type') }}
            </a>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            @if (session('status') === 'event-type-deleted')
                <div class="alert-info mb-6" role="status"><x-icon name="check" class="mt-0.5 h-4 w-4" /> {{ __('Event type deleted.') }}</div>
            @endif

            @if ($eventTypes->isEmpty())
                <div class="card flex flex-col items-center px-6 py-16 text-center">
                    <div class="flex h-12 w-20 items-center justify-center rounded-full bg-primary-soft text-primary-ink"><x-icon name="layers" /></div>
                    <p class="mt-4 font-semibold text-ink">{{ __('No event types yet') }}</p>
                    <p class="mt-1 muted">{{ __('Create one to get a booking link you can share.') }}</p>
                    <a href="{{ route('event-types.create') }}" class="btn-primary mt-6"><x-icon name="plus" class="h-4 w-4" /> {{ __('New event type') }}</a>
                </div>
            @else
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($eventTypes as $eventType)
                        <article class="card relative flex flex-col p-6 transition-colors hover:border-primary/40" x-data="{ copied: false }">
                            <div class="flex items-start justify-between gap-3">
                                <span class="flex h-10 w-[4.5rem] items-center justify-center rounded-full text-sm font-bold tabular-nums {{ $eventType->is_active ? 'bg-primary-soft text-primary-ink' : 'bg-surface-2 text-ink-3' }}">
                                    {{ $eventType->duration_minutes }}<span class="ms-0.5 font-semibold">{{ __('min') }}</span>
                                </span>
                                @unless ($eventType->is_active)
                                    <span class="pill-neutral"><x-icon name="eye-off" class="h-3.5 w-3.5" /> {{ __('Off') }}</span>
                                @endunless
                            </div>

                            <h2 class="mt-5">
                                {{-- The stretched link makes the whole card clickable. --}}
                                <a href="{{ route('event-types.edit', $eventType) }}" class="text-lg font-semibold text-ink after:absolute after:inset-0 after:rounded-2xl hover:underline">{{ $eventType->title }}</a>
                            </h2>
                            <p class="mt-1 flex items-center gap-1.5 text-sm text-ink-3">
                                <x-icon :name="match ($eventType->location_type) { LocationType::GoogleMeet => 'video', LocationType::Phone => 'phone', LocationType::Custom => 'map-pin' }" class="h-4 w-4" />
                                {{ $eventType->location_type->label() }}
                            </p>

                            <div class="relative z-10 mt-6 flex items-center gap-2 border-t border-line pt-4">
                                <span class="min-w-0 flex-1 truncate text-sm text-ink-3">/{{ auth()->user()->username }}/{{ $eventType->slug }}</span>
                                <button type="button" class="btn-ghost min-h-0 px-2.5 py-1.5 text-xs"
                                        @click="navigator.clipboard.writeText(@js($eventType->publicUrl())); copied = true; setTimeout(() => copied = false, 1500)">
                                    <x-icon name="copy" class="h-3.5 w-3.5" x-show="! copied" />
                                    <x-icon name="check" class="h-3.5 w-3.5" x-show="copied" x-cloak />
                                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))">{{ __('Copy link') }}</span>
                                </button>
                                <a href="{{ $eventType->publicUrl() }}" target="_blank" rel="noopener" class="btn-ghost min-h-0 px-2.5 py-1.5" aria-label="{{ __('Open booking page') }}"><x-icon name="external" class="h-3.5 w-3.5" /></a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
