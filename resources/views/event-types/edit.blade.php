<x-app-layout :title="$eventType->title">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="page-title">
                {{ $eventType->title }}
            </h1>
            <a href="{{ route('event-types.index') }}" class="text-sm text-ink-2 hover:text-ink">{{ __('All event types') }}</a>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 space-y-6">
            <form method="post" action="{{ route('event-types.update', $eventType) }}">
                @method('put')
                @include('event-types.partials.form')

                <div class="mt-6 flex items-center gap-4">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    @if (session('status') === 'event-type-created')
                        <p class="text-sm text-ink-2">{{ __('Event type created.') }}</p>
                    @elseif (session('status') === 'event-type-updated')
                        <p class="text-sm text-ink-2">{{ __('Saved.') }}</p>
                    @endif
                </div>
            </form>

            <div class="card p-5 sm:p-8">
                <form method="post" action="{{ route('event-types.destroy', $eventType) }}"
                      onsubmit="return confirm(@js(__('Delete this event type? Its booking link will stop working.')))">
                    @csrf
                    @method('delete')
                    <x-danger-button>{{ __('Delete event type') }}</x-danger-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
