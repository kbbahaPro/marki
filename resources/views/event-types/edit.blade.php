<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $eventType->title }}
            </h2>
            <a href="{{ route('event-types.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('All event types') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <form method="post" action="{{ route('event-types.update', $eventType) }}">
                @method('put')
                @include('event-types.partials.form')

                <div class="mt-6 flex items-center gap-4">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    @if (session('status') === 'event-type-created')
                        <p class="text-sm text-gray-600">{{ __('Event type created.') }}</p>
                    @elseif (session('status') === 'event-type-updated')
                        <p class="text-sm text-gray-600">{{ __('Saved.') }}</p>
                    @endif
                </div>
            </form>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
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
