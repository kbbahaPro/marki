@php
    use App\Enums\BookingStatus;
    use App\Enums\LocationType;
    use App\Enums\SyncStatus;

    $start = $booking->start_at->setTimezone($timezone);
    $end = $booking->end_at->setTimezone($timezone);
    $guestStart = $booking->start_at->setTimezone($booking->guest_timezone);

    $viewer = auth()->user();
    $team = $booking->eventType->team;
    // Team admins who do not attend came from the team's Bookings page.
    $fromTeam = $team && ! $booking->hosts->contains($viewer);
    $organizerIsViewer = $booking->host_id === $viewer->id;
@endphp

<x-app-layout :title="$booking->guest_name">
    <x-slot name="header">
        <div class="mx-auto max-w-3xl">
            @if ($fromTeam)
                <a href="{{ route('teams.bookings', $team) }}" class="btn-ghost -ms-3 min-h-0 py-1.5 text-sm"><x-icon name="arrow-left" class="h-4 w-4" />{{ __(':team bookings', ['team' => $team->name]) }}</a>
            @else
                <a href="{{ route('dashboard') }}" class="btn-ghost -ms-3 min-h-0 py-1.5 text-sm"><x-icon name="arrow-left" class="h-4 w-4" />{{ __('All bookings') }}</a>
            @endif
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <span class="rounded-full px-4 py-2 text-lg font-bold tabular-nums {{ $booking->status === BookingStatus::Confirmed ? 'bg-primary text-white' : 'bg-surface-2 text-ink-3 line-through' }}">{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</span>
                <div class="min-w-0">
                    <h1 class="page-title truncate">{{ $booking->guest_name }}</h1>
                    <p class="muted">{{ $booking->eventType->title }} · {{ $start->translatedFormat('l, F j, Y') }}</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('error'))
                <div class="alert-danger" role="alert">{{ session('error') }}</div>
            @endif
            @switch(session('status'))
                @case('booking-cancelled')
                    <div class="alert-info">{{ __('Booking cancelled. :guest has been emailed.', ['guest' => $booking->guest_name]) }}</div>
                    @break
                @case('booking-rescheduled')
                    <div class="alert-success">{{ __('Booking moved. :guest has been emailed the new time.', ['guest' => $booking->guest_name]) }}</div>
                    @break
                @case('booking-completed')
                    <div class="alert-success">{{ __('Marked as completed.') }}</div>
                    @break
                @case('booking-no-show')
                    <div class="alert-info">{{ __('Marked as a no-show.') }}</div>
                    @break
                @case('sync-queued')
                    <div class="alert-info">{{ __('Trying again to add it to your calendar. This can take a minute.') }}</div>
                    @break
            @endswitch

            <div class="card p-5 sm:p-8">
                <div class="flex flex-wrap items-center gap-2">
                    @switch($booking->status)
                        @case(BookingStatus::Confirmed)
                            <span class="pill-success">{{ $booking->end_at->isPast() ? __('Ended') : __('Confirmed') }}</span>
                            @break
                        @case(BookingStatus::Cancelled)
                            <span class="pill-neutral">{{ $booking->cancelledByLabel($viewer) }}</span>
                            @break
                        @case(BookingStatus::Rescheduled)
                            <span class="pill-primary">{{ __('Moved to a new time') }}</span>
                            @break
                        @case(BookingStatus::Completed)
                            <span class="pill-success">{{ __('Completed') }}</span>
                            @break
                        @case(BookingStatus::NoShow)
                            <span class="pill-danger">{{ __('No-show') }}</span>
                            @break
                    @endswitch

                    @if ($booking->canRecordOutcome())
                        {{-- Correctable: hosts can switch between the two outcomes. --}}
                        <span class="ms-auto flex flex-wrap gap-2">
                            @foreach ([BookingStatus::Completed->value => __('Mark as completed'), BookingStatus::NoShow->value => __('Mark as no-show')] as $outcome => $label)
                                @continue($booking->status->value === $outcome)
                                <form method="post" action="{{ route('dashboard.bookings.outcome', $booking) }}">
                                    @csrf
                                    <input type="hidden" name="outcome" value="{{ $outcome }}">
                                    <button class="btn-secondary min-h-0 py-1.5 text-sm">{{ $label }}</button>
                                </form>
                            @endforeach
                        </span>
                    @endif
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-[9rem_1fr] text-sm">
                    <dt class="text-ink-3">{{ __('When') }}</dt>
                    <dd class="text-ink">
                        {{ $start->translatedFormat('l, F j, Y') }}, {{ $start->format('H:i') }} – {{ $end->format('H:i') }}
                        <span class="text-ink-3">({{ str_replace('_', ' ', $timezone) }})</span>
                        @if ($booking->guest_timezone !== $timezone)
                            <div class="text-ink-3">{{ __('Guest\'s time: :time (:tz)', ['time' => $guestStart->translatedFormat('D H:i'), 'tz' => str_replace('_', ' ', $booking->guest_timezone)]) }}</div>
                        @endif
                    </dd>

                    @if ($team)
                        <dt class="text-ink-3">{{ __('Hosts') }}</dt>
                        <dd class="space-y-2 text-ink">
                            @foreach ($booking->hosts as $host)
                                <div class="flex items-center gap-2">
                                    <x-avatar :user="$host" size="sm" decorative />
                                    <span>{{ $host->name }}@if ($host->is($viewer)) <span class="text-ink-3">({{ __('you') }})</span>@endif</span>
                                    @if ($host->id === $booking->host_id && $booking->hosts->count() > 1)
                                        <span class="pill-neutral">{{ __('Organizer') }}</span>
                                    @endif
                                </div>
                            @endforeach
                            <div class="text-ink-3">{{ $booking->eventType->scheduling_type->label() }} · {{ $team->name }}</div>
                        </dd>
                    @endif

                    <dt class="text-ink-3">{{ __('Where') }}</dt>
                    <dd class="text-ink">
                        @if ($booking->meeting_url)
                            <a href="{{ $booking->meeting_url }}" class="text-primary-ink underline" target="_blank" rel="noopener">{{ $booking->meeting_url }}</a>
                        @elseif ($booking->location_type === LocationType::Phone)
                            {{ __('Call :phone', ['phone' => $booking->guest_phone]) }}
                        @elseif ($booking->location_type === LocationType::Custom)
                            {{ $booking->location_value }}
                        @else
                            {{ __('Google Meet (no link yet)') }}
                        @endif
                    </dd>

                    <dt class="text-ink-3">{{ __('Guest') }}</dt>
                    <dd class="text-ink">
                        {{ $booking->guest_name }}<br>
                        <a href="mailto:{{ $booking->guest_email }}" class="text-primary-ink underline">{{ $booking->guest_email }}</a>
                        @if ($booking->guest_phone)
                            <br>{{ $booking->guest_phone }}
                        @endif
                    </dd>

                    @foreach ($booking->answers as $answer)
                        <dt class="text-ink-3">{{ $answer->label }}</dt>
                        <dd class="text-ink whitespace-pre-line">{{ $answer->value ?? '—' }}</dd>
                    @endforeach

                    @if ($booking->guest_notes)
                        <dt class="text-ink-3">{{ __('Notes') }}</dt>
                        <dd class="text-ink whitespace-pre-line">{{ $booking->guest_notes }}</dd>
                    @endif

                    @if ($booking->status === BookingStatus::Cancelled && $booking->cancel_reason)
                        <dt class="text-ink-3">{{ __('Cancellation reason') }}</dt>
                        <dd class="text-ink whitespace-pre-line">{{ $booking->cancel_reason }}</dd>
                    @endif

                    <dt class="text-ink-3">{{ __('Calendar') }}</dt>
                    <dd class="text-ink">
                        @switch($booking->sync_status)
                            @case(SyncStatus::Synced) {{ $organizerIsViewer ? __('In your Google Calendar') : __('In :name’s Google Calendar', ['name' => $booking->host->name]) }} @break
                            @case(SyncStatus::Skipped) {{ $organizerIsViewer ? __('Google Calendar not connected') : __(':name has not connected Google Calendar', ['name' => $booking->host->name]) }} @break
                            @case(SyncStatus::Pending) {{ __('Being added…') }} @break
                            @case(SyncStatus::Failed)
                                <span class="text-danger">{{ $organizerIsViewer ? __('Could not be added to your calendar.') : __('Could not be added to :name’s calendar.', ['name' => $booking->host->name]) }}</span>
                                @if ($booking->status === BookingStatus::Confirmed)
                                    <form method="post" action="{{ route('dashboard.bookings.sync', $booking) }}" class="inline">
                                        @csrf
                                        <button class="ms-2 text-primary-ink underline">{{ __('Try again') }}</button>
                                    </form>
                                @endif
                                @break
                        @endswitch
                    </dd>

                    @if ($booking->rescheduledFrom || $booking->rescheduledTo)
                        <dt class="text-ink-3">{{ __('History') }}</dt>
                        <dd class="text-ink space-y-1">
                            @if ($booking->rescheduledFrom)
                                <div><a href="{{ route('dashboard.bookings.show', $booking->rescheduledFrom) }}" class="text-primary-ink underline">{{ __('Moved from :time', ['time' => $booking->rescheduledFrom->start_at->setTimezone($timezone)->translatedFormat('D, M j, H:i')]) }}</a></div>
                            @endif
                            @if ($booking->rescheduledTo)
                                <div><a href="{{ route('dashboard.bookings.show', $booking->rescheduledTo) }}" class="text-primary-ink underline">{{ __('Moved to :time', ['time' => $booking->rescheduledTo->start_at->setTimezone($timezone)->translatedFormat('D, M j, H:i')]) }}</a></div>
                            @endif
                        </dd>
                    @endif
                </dl>
            </div>

            @if ($booking->isChangeable())
                <div class="card p-5 sm:p-8 space-y-6">
                    <div>
                        <h3 class="font-medium text-ink">{{ __('Move this booking') }}</h3>
                        <p class="mt-1 text-sm text-ink-2">{{ __('Pick a new time; :guest gets an email with an updated invitation.', ['guest' => $booking->guest_name]) }}</p>
                        <a href="{{ route('dashboard.bookings.reschedule', $booking) }}" class="mt-3 btn-primary">{{ __('Reschedule') }}</a>
                    </div>

                    <form method="post" action="{{ route('dashboard.bookings.cancel', $booking) }}" class="space-y-3 border-t border-line pt-6"
                          onsubmit="return confirm(@js(__('Cancel this booking? :guest will be emailed.', ['guest' => $booking->guest_name])))">
                        @csrf
                        <h3 class="font-medium text-ink">{{ __('Cancel this booking') }}</h3>
                        <div>
                            <x-input-label for="reason" :value="__('Message to :guest (optional)', ['guest' => $booking->guest_name])" />
                            <textarea id="reason" name="reason" rows="3" maxlength="1000" class="mt-1 block w-full input">{{ old('reason') }}</textarea>
                            <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                        </div>
                        <x-danger-button>{{ __('Cancel booking') }}</x-danger-button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
