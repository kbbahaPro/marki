<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New event type') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form method="post" action="{{ route('event-types.store') }}">
                @include('event-types.partials.form')

                <div class="mt-6 flex items-center gap-4">
                    <x-primary-button>{{ __('Create') }}</x-primary-button>
                    <a href="{{ route('event-types.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
