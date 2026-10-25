@php
    use App\Enums\BookingStatus;
    use App\Enums\SyncStatus;
    use Illuminate\Support\Carbon;
@endphp

{{-- Bookings grouped by day. Expects $days, $tab and $tz. --}}
@foreach ($days as $date => $dayBookings)
    @php($day = Carbon::parse($date, $tz))
    <h3 class="bg-surface-2/60 px-5 py-2 text-xs font-semibold uppercase tracking-wider text-ink-3">
        {{ $day->isToday() ? __('Today') : ($day->isTomorrow() ? __('Tomorrow') : $day->translatedFormat('l, F j')) }}
        @unless ($day->isCurrentYear()) {{ $day->year }} @endunless
    </h3>
    <ul class="divide-y divide-line">
        @foreach ($dayBookings as $booking)
            @php($start = $booking->start_at->setTimezone($tz))
            <li>
                <a href="{{ route('dashboard.bookings.show', $booking) }}" class="group flex items-center gap-4 px-5 py-4 transition-colors hover:bg-surface-2/60">
                    {{-- The time slot, in its pill --}}
                    <span class="w-[7.5rem] shrink-0 rounded-full py-1.5 text-center text-sm font-semibold tabular-nums {{ $tab === 'cancelled' ? 'bg-surface-2 text-ink-3 line-through' : 'bg-primary-soft text-primary-ink' }}">
                        {{ $start->format('H:i') }}–{{ $booking->end_at->setTimezone($tz)->format('H:i') }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-ink">{{ $booking->guest_name }}</span>
                        <span class="block truncate text-sm text-ink-3">{{ $booking->eventType->title }}@if ($booking->eventType->team) · {{ $booking->eventType->team->name }}@endif · {{ $booking->guest_email }}</span>
                    </span>
                    <span class="hidden shrink-0 items-center gap-2 sm:flex">
                        @if ($tab === 'cancelled')
                            <span class="pill-neutral">{{ $booking->cancelledByLabel(auth()->user()) }}</span>
                        @elseif ($booking->status === BookingStatus::NoShow)
                            <span class="pill-danger">{{ __('No-show') }}</span>
                        @elseif ($booking->sync_status === SyncStatus::Failed && $booking->status === BookingStatus::Confirmed)
                            <span class="pill-danger">{{ __('Not in calendar') }}</span>
                        @endif
                        @if ($booking->rescheduled_from_id && $tab !== 'cancelled')
                            <span class="pill-primary">{{ __('Rescheduled') }}</span>
                        @endif
                    </span>
                    @if ($booking->hosts->count() > 1 || ! $booking->hosts->contains(auth()->user()))
                        {{-- Team bookings: who attends --}}
                        <span class="hidden shrink-0 -space-x-2 md:flex" title="{{ $booking->hostNames() }}">
                            @foreach ($booking->hosts->take(3) as $host)
                                <x-avatar :user="$host" size="sm" class="ring-2 ring-surface" />
                            @endforeach
                        </span>
                    @endif
                    <x-icon name="chevron-right" class="h-4 w-4 text-ink-3 transition-transform group-hover:translate-x-0.5" />
                </a>
            </li>
        @endforeach
    </ul>
@endforeach
