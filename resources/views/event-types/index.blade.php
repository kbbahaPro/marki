<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Event types') }}
            </h2>
            <a href="{{ route('event-types.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                {{ __('New event type') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status') === 'event-type-deleted')
                <p class="mb-4 text-sm text-gray-600">{{ __('Event type deleted.') }}</p>
            @endif

            @if ($eventTypes->isEmpty())
                <div class="p-6 bg-white shadow sm:rounded-lg text-gray-600">
                    {{ __('No event types yet. Create one to get a booking link you can share.') }}
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($eventTypes as $eventType)
                        <div class="p-6 bg-white shadow sm:rounded-lg flex flex-col gap-3 {{ $eventType->is_active ? '' : 'opacity-60' }}">
                            <div>
                                <a href="{{ route('event-types.edit', $eventType) }}" class="text-lg font-medium text-gray-900 hover:underline">{{ $eventType->title }}</a>
                                <p class="text-sm text-gray-500">
                                    {{ trans_choice(':count minute|:count minutes', $eventType->duration_minutes) }} · {{ $eventType->location_type->label() }}
                                    @unless ($eventType->is_active) · {{ __('Off') }} @endunless
                                </p>
                            </div>
                            <div class="mt-auto flex items-center gap-3 text-sm" x-data="{ copied: false }">
                                <span class="truncate text-gray-500">/{{ auth()->user()->username }}/{{ $eventType->slug }}</span>
                                <button type="button" class="shrink-0 text-indigo-600 hover:text-indigo-800"
                                        @click="navigator.clipboard.writeText(@js($eventType->publicUrl())); copied = true; setTimeout(() => copied = false, 1500)"
                                        x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))"></button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
