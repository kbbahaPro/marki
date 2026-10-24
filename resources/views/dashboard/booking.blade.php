@php
    use App\Enums\BookingStatus;
    use App\Enums\LocationType;
    use App\Enums\SyncStatus;

    $start = $booking->start_at->setTimezone($timezone);
    $end = $booking->end_at->setTimezone($timezone);
    $guestStart = $booking->start_at->setTimezone($booking->guest_timezone);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $booking->guest_name }} · {{ $booking->eventType->title }}
            </h2>
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('All bookings') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('error'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
            @endif
            @switch(session('status'))
                @case('booking-cancelled')
                    <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">{{ __('Booking cancelled. :guest has been emailed.', ['guest' => $booking->guest_name]) }}</div>
                    @break
                @case('booking-rescheduled')
                    <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">{{ __('Booking moved. :guest has been emailed the new time.', ['guest' => $booking->guest_name]) }}</div>
                    @break
                @case('sync-queued')
                    <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">{{ __('Trying again to add it to your calendar. This can take a minute.') }}</div>
                    @break
            @endswitch

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex flex-wrap items-center gap-2">
                    @switch($booking->status)
                        @case(BookingStatus::Confirmed)
                            <span class="rounded bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">{{ $booking->end_at->isPast() ? __('Completed') : __('Confirmed') }}</span>
                            @break
                        @case(BookingStatus::Cancelled)
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $booking->cancelled_by === 'host' ? __('Cancelled by you') : __('Cancelled by guest') }}</span>
                            @break
                        @case(BookingStatus::Rescheduled)
                            <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ __('Moved to a new time') }}</span>
                            @break
                    @endswitch
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-[9rem_1fr] text-sm">
                    <dt class="text-gray-500">{{ __('When') }}</dt>
                    <dd class="text-gray-900">
                        {{ $start->translatedFormat('l, F j, Y') }}, {{ $start->format('H:i') }} – {{ $end->format('H:i') }}
                        <span class="text-gray-500">({{ str_replace('_', ' ', $timezone) }})</span>
                        @if ($booking->guest_timezone !== $timezone)
                            <div class="text-gray-500">{{ __('Guest\'s time: :time (:tz)', ['time' => $guestStart->translatedFormat('D H:i'), 'tz' => str_replace('_', ' ', $booking->guest_timezone)]) }}</div>
                        @endif
                    </dd>

                    <dt class="text-gray-500">{{ __('Where') }}</dt>
                    <dd class="text-gray-900">
                        @if ($booking->meeting_url)
                            <a href="{{ $booking->meeting_url }}" class="text-indigo-600 underline" target="_blank" rel="noopener">{{ $booking->meeting_url }}</a>
                        @elseif ($booking->location_type === LocationType::Phone)
                            {{ __('Call :phone', ['phone' => $booking->guest_phone]) }}
                        @elseif ($booking->location_type === LocationType::Custom)
                            {{ $booking->location_value }}
                        @else
                            {{ __('Google Meet (no link yet)') }}
                        @endif
                    </dd>

                    <dt class="text-gray-500">{{ __('Guest') }}</dt>
                    <dd class="text-gray-900">
                        {{ $booking->guest_name }}<br>
                        <a href="mailto:{{ $booking->guest_email }}" class="text-indigo-600 underline">{{ $booking->guest_email }}</a>
                        @if ($booking->guest_phone)
                            <br>{{ $booking->guest_phone }}
                        @endif
                    </dd>

                    @foreach ($booking->answers as $answer)
                        <dt class="text-gray-500">{{ $answer->label }}</dt>
                        <dd class="text-gray-900 whitespace-pre-line">{{ $answer->value ?? '—' }}</dd>
                    @endforeach

                    @if ($booking->guest_notes)
                        <dt class="text-gray-500">{{ __('Notes') }}</dt>
                        <dd class="text-gray-900 whitespace-pre-line">{{ $booking->guest_notes }}</dd>
                    @endif

                    @if ($booking->status === BookingStatus::Cancelled && $booking->cancel_reason)
                        <dt class="text-gray-500">{{ __('Cancellation reason') }}</dt>
                        <dd class="text-gray-900 whitespace-pre-line">{{ $booking->cancel_reason }}</dd>
                    @endif

                    <dt class="text-gray-500">{{ __('Calendar') }}</dt>
                    <dd class="text-gray-900">
                        @switch($booking->sync_status)
                            @case(SyncStatus::Synced) {{ __('In your Google Calendar') }} @break
                            @case(SyncStatus::Skipped) {{ __('Google Calendar not connected') }} @break
                            @case(SyncStatus::Pending) {{ __('Being added…') }} @break
                            @case(SyncStatus::Failed)
                                <span class="text-red-700">{{ __('Could not be added to your calendar.') }}</span>
                                @if ($booking->status === BookingStatus::Confirmed)
                                    <form method="post" action="{{ route('dashboard.bookings.sync', $booking) }}" class="inline">
                                        @csrf
                                        <button class="ms-2 text-indigo-600 underline">{{ __('Try again') }}</button>
                                    </form>
                                @endif
                                @break
                        @endswitch
                    </dd>

                    @if ($booking->rescheduledFrom || $booking->rescheduledTo)
                        <dt class="text-gray-500">{{ __('History') }}</dt>
                        <dd class="text-gray-900 space-y-1">
                            @if ($booking->rescheduledFrom)
                                <div><a href="{{ route('dashboard.bookings.show', $booking->rescheduledFrom) }}" class="text-indigo-600 underline">{{ __('Moved from :time', ['time' => $booking->rescheduledFrom->start_at->setTimezone($timezone)->translatedFormat('D, M j, H:i')]) }}</a></div>
                            @endif
                            @if ($booking->rescheduledTo)
                                <div><a href="{{ route('dashboard.bookings.show', $booking->rescheduledTo) }}" class="text-indigo-600 underline">{{ __('Moved to :time', ['time' => $booking->rescheduledTo->start_at->setTimezone($timezone)->translatedFormat('D, M j, H:i')]) }}</a></div>
                            @endif
                        </dd>
                    @endif
                </dl>
            </div>

            @if ($booking->isChangeable())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-6">
                    <div>
                        <h3 class="font-medium text-gray-900">{{ __('Move this booking') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ __('Pick a new time; :guest gets an email with an updated invitation.', ['guest' => $booking->guest_name]) }}</p>
                        <a href="{{ route('dashboard.bookings.reschedule', $booking) }}" class="mt-3 inline-flex items-center px-4 py-2 bg-gray-800 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">{{ __('Reschedule') }}</a>
                    </div>

                    <form method="post" action="{{ route('dashboard.bookings.cancel', $booking) }}" class="space-y-3 border-t border-gray-100 pt-6"
                          onsubmit="return confirm(@js(__('Cancel this booking? :guest will be emailed.', ['guest' => $booking->guest_name])))">
                        @csrf
                        <h3 class="font-medium text-gray-900">{{ __('Cancel this booking') }}</h3>
                        <div>
                            <x-input-label for="reason" :value="__('Message to :guest (optional)', ['guest' => $booking->guest_name])" />
                            <textarea id="reason" name="reason" rows="3" maxlength="1000" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                            <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                        </div>
                        <x-danger-button>{{ __('Cancel booking') }}</x-danger-button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
