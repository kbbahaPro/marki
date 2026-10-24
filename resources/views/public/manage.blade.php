@php
    use App\Enums\BookingStatus;

    $tz = $booking->guest_timezone;
    $start = $booking->start_at->setTimezone($tz);
    $end = $booking->end_at->setTimezone($tz);
    $canBookAgain = $booking->eventType->is_active && ! $booking->eventType->trashed();
@endphp

<x-public-layout :title="__('Your booking')">
    <div class="bg-white shadow sm:rounded-lg p-6 sm:p-8 max-w-xl mx-auto">
        @if (session('error'))
            <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
        @endif

        @switch(true)
            @case($booking->status === BookingStatus::Cancelled)
                <h1 class="text-2xl font-semibold text-gray-900">{{ __('This booking is cancelled') }}</h1>
                @if (session('status') === 'cancelled')
                    <p class="mt-1 text-gray-600">{{ __('We have let :host know. A confirmation is on its way to your inbox.', ['host' => $booking->host->name]) }}</p>
                @endif
                @break
            @case($booking->status === BookingStatus::Rescheduled)
                <h1 class="text-2xl font-semibold text-gray-900">{{ __('This booking was moved') }}</h1>
                <p class="mt-1 text-gray-600">{{ __('It was rescheduled to a new time. Please use the links in your most recent email.') }}</p>
                @break
            @case($booking->start_at->isPast())
                <h1 class="text-2xl font-semibold text-gray-900">{{ __('This meeting has already started') }}</h1>
                <p class="mt-1 text-gray-600">{{ __('It can no longer be changed online. Reply to your confirmation email to reach :host.', ['host' => $booking->host->name]) }}</p>
                @break
            @default
                <h1 class="text-2xl font-semibold text-gray-900">{{ __('Your booking') }}</h1>
        @endswitch

        <dl class="mt-6 space-y-3 border-t border-gray-100 pt-6 text-sm">
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-gray-500">{{ __('What') }}</dt><dd class="font-medium text-gray-900">{{ $booking->eventType->title }} {{ __('with') }} {{ $booking->host->name }}</dd></div>
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-gray-500">{{ __('When') }}</dt><dd class="text-gray-900 {{ $booking->isChangeable() ? '' : 'line-through text-gray-500' }}">
                {{ $start->translatedFormat('l, F j, Y') }}<br>
                {{ $start->format('H:i') }} – {{ $end->format('H:i') }} <span class="text-gray-500">({{ str_replace('_', ' ', $tz) }})</span>
            </dd></div>
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-gray-500">{{ __('Who') }}</dt><dd class="text-gray-900">{{ $booking->guest_name }}</dd></div>
        </dl>

        @if ($booking->isChangeable())
            <div class="mt-8 space-y-6">
                @if ($canBookAgain)
                    <a href="{{ route('bookings.reschedule', $token) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        {{ __('Pick a new time') }}
                    </a>
                @endif

                <form method="post" action="{{ route('bookings.cancel', $token) }}" class="space-y-3 border-t border-gray-100 pt-6"
                      onsubmit="return confirm(@js(__('Cancel this booking?')))">
                    @csrf
                    <h2 class="font-medium text-gray-900">{{ __('Cancel this booking') }}</h2>
                    <div>
                        <x-input-label for="reason" :value="__('Reason (optional, shared with :host)', ['host' => $booking->host->name])" />
                        <textarea id="reason" name="reason" rows="3" maxlength="1000" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                    </div>
                    <x-danger-button>{{ __('Cancel booking') }}</x-danger-button>
                </form>
            </div>
        @elseif ($booking->status === BookingStatus::Cancelled && $canBookAgain)
            <a href="{{ route('public.book', [$booking->host->username, $booking->eventType->slug]) }}" class="mt-8 inline-flex items-center px-4 py-2 bg-gray-800 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                {{ __('Book a new time') }}
            </a>
        @endif
    </div>
</x-public-layout>
