<x-public-layout :title="$host->name">
    <div class="bg-white shadow sm:rounded-lg p-6 sm:p-8">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $host->name }}</h1>
        <p class="mt-1 text-gray-600">{{ __('Pick a meeting type to see available times.') }}</p>

        @if ($eventTypes->isEmpty())
            <p class="mt-6 text-gray-500">{{ __('No meetings are available to book right now.') }}</p>
        @else
            <ul class="mt-6 divide-y divide-gray-100 border-t border-gray-100">
                @foreach ($eventTypes as $eventType)
                    <li>
                        <a href="{{ route('public.book', [$host->username, $eventType->slug]) }}" class="flex items-center justify-between gap-4 py-4 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <span>
                                <span class="block font-medium text-gray-900">{{ $eventType->title }}</span>
                                <span class="block text-sm text-gray-500">{{ trans_choice(':count minute|:count minutes', $eventType->duration_minutes) }} · {{ $eventType->location_type->label() }}</span>
                            </span>
                            <span class="text-gray-400" aria-hidden="true">→</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-public-layout>
