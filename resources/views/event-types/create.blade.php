<x-app-layout :title="__('New event type')">
    <x-slot name="header">
        <h1 class="page-title">
            {{ __('New event type') }}
        </h1>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <form method="post" action="{{ route('event-types.store') }}">
                @include('event-types.partials.form')

                <div class="mt-6 flex items-center gap-4">
                    <x-primary-button>{{ __('Create') }}</x-primary-button>
                    <a href="{{ route('event-types.index') }}" class="text-sm text-ink-2 hover:text-ink">{{ __('Cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
