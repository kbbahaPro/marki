@php
    use App\Enums\BookingStatus;
    use App\Enums\LocationType;

    $tz = $booking->guest_timezone;
    $start = $booking->start_at->setTimezone($tz);
    $end = $booking->end_at->setTimezone($tz);
@endphp

<x-public-layout :title="__('Booking confirmed')">
    <div class="bg-white shadow sm:rounded-lg p-6 sm:p-8 max-w-xl mx-auto text-center">
        @if ($booking->status === BookingStatus::Confirmed)
            <div class="text-4xl" aria-hidden="true">✓</div>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ __('You are scheduled') }}</h1>
            <p class="mt-1 text-gray-600">{{ __('A confirmation has been sent to your email address.') }}</p>
        @else
            <h1 class="text-2xl font-semibold text-gray-900">{{ __('This booking was cancelled') }}</h1>
        @endif

        <dl class="mt-6 space-y-3 text-left border-t border-gray-100 pt-6 text-sm">
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-gray-500">{{ __('What') }}</dt><dd class="font-medium text-gray-900">{{ $booking->eventType->title }} {{ __('with') }} {{ $booking->host->name }}</dd></div>
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-gray-500">{{ __('When') }}</dt><dd class="text-gray-900">
                {{ $start->translatedFormat('l, F j, Y') }}<br>
                {{ $start->format('H:i') }} – {{ $end->format('H:i') }} <span class="text-gray-500">({{ str_replace('_', ' ', $tz) }})</span>
            </dd></div>
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-gray-500">{{ __('Where') }}</dt><dd class="text-gray-900">
                @if ($booking->meeting_url)
                    <a href="{{ $booking->meeting_url }}" class="text-indigo-600 underline" rel="noopener">{{ $booking->meeting_url }}</a>
                @elseif ($booking->location_type === LocationType::Custom)
                    {{ $booking->location_value }}
                @elseif ($booking->location_type === LocationType::Phone)
                    {{ __(':name will call you.', ['name' => $booking->host->name]) }}
                @else
                    {{ __('A Google Meet link will be in your confirmation email.') }}
                @endif
            </dd></div>
        </dl>
    </div>
</x-public-layout>
