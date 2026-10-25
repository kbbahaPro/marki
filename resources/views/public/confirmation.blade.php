@php
    use App\Enums\BookingStatus;
    use App\Enums\LocationType;

    $tz = $booking->guest_timezone;
    $start = $booking->start_at->setTimezone($tz);
    $end = $booking->end_at->setTimezone($tz);
    $confirmed = $booking->status->isKept();
@endphp

<x-public-layout :title="$confirmed ? __('Booking confirmed') : __('Booking cancelled')">
    <div class="card mx-auto max-w-xl overflow-hidden shadow-xl shadow-ink/[0.04]">
        {{-- The booked slot, as the motif: a pill holding the confirmed time --}}
        <div class="relative overflow-hidden px-6 pb-8 pt-10 text-center sm:px-10 {{ $confirmed ? 'bg-primary text-white' : 'bg-surface-2 text-ink' }}">
            <div class="motif -bottom-24 -left-20 h-40 w-80 bg-white/10" aria-hidden="true"></div>
            <div class="motif -right-24 -top-16 h-32 w-72 bg-white/10" aria-hidden="true"></div>

            <div class="relative mx-auto flex h-14 w-24 items-center justify-center rounded-full {{ $confirmed ? 'marked' : 'bg-surface text-ink-3' }}">
                <x-icon :name="$confirmed ? 'check' : 'x'" class="h-7 w-7" />
            </div>
            <h1 class="relative mt-5 text-2xl font-bold tracking-tight">
                @if ($confirmed)
                    {{ session('rescheduled') ? __('Your booking has a new time') : __('You are scheduled') }}
                @else
                    {{ __('This booking was cancelled') }}
                @endif
            </h1>
            @if ($confirmed)
                <p class="relative mt-1 text-sm text-white/80">{{ __('A confirmation with a calendar invitation is on its way to your inbox.') }}</p>
            @endif
        </div>

        <dl class="divide-y divide-line px-6 sm:px-10">
            <div class="flex items-center gap-4 py-4">
                <dt class="sr-only">{{ __('What') }}</dt>
                @if ($booking->hosts->count() > 1)
                    <span class="flex shrink-0 -space-x-3">
                        @foreach ($booking->hosts->take(3) as $host)
                            <x-avatar :user="$host" size="md" decorative class="ring-2 ring-surface" />
                        @endforeach
                    </span>
                @else
                    <x-avatar :user="$booking->host" size="md" decorative />
                @endif
                <dd>
                    <span class="font-semibold text-ink">{{ $booking->eventType->title }}</span><br>
                    <span class="text-sm text-ink-2">{{ __('with') }} {{ $booking->hostNames() }}@if ($booking->hosts->count() <= 1 && $booking->host->headline()) · {{ $booking->host->headline() }}@endif</span>
                </dd>
            </div>
            <div class="flex gap-4 py-4">
                <dt class="sr-only">{{ __('When') }}</dt>
                <x-icon name="calendar" class="mt-0.5 h-5 w-5 text-ink-3" />
                <dd class="{{ $confirmed ? '' : 'line-through' }}">
                    <span class="font-semibold text-ink">{{ $start->translatedFormat('l, F j, Y') }}</span><br>
                    <span class="text-sm text-ink-2 tabular-nums">{{ $start->format('H:i') }} – {{ $end->format('H:i') }} · {{ str_replace('_', ' ', $tz) }}</span>
                </dd>
            </div>
            <div class="flex gap-4 py-4">
                <dt class="sr-only">{{ __('Where') }}</dt>
                <x-icon :name="$booking->location_type === LocationType::Phone ? 'phone' : ($booking->location_type === LocationType::Custom ? 'map-pin' : 'video')" class="mt-0.5 h-5 w-5 text-ink-3" />
                <dd class="min-w-0 text-sm text-ink">
                    @if ($booking->meeting_url)
                        <a href="{{ $booking->meeting_url }}" class="link break-all" rel="noopener">{{ $booking->meeting_url }}</a>
                    @elseif ($booking->location_type === LocationType::Custom)
                        {{ $booking->location_value }}
                    @elseif ($booking->location_type === LocationType::Phone)
                        {{ __(':name will call you.', ['name' => $booking->host->name]) }}
                    @else
                        {{ __('A Google Meet link will be in your confirmation email.') }}
                    @endif
                </dd>
            </div>
        </dl>

        {{-- The token is only in the session right after booking; afterwards it lives in the guest's email. --}}
        @if (session('manage_token') && $confirmed)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface-2/50 px-6 py-4 sm:px-10">
                <span class="text-sm text-ink-2">{{ __('Need to make a change?') }}</span>
                <div class="flex gap-2">
                    <a href="{{ route('bookings.reschedule', session('manage_token')) }}" class="btn-secondary min-h-0 py-2">{{ __('Reschedule') }}</a>
                    <a href="{{ route('bookings.manage', session('manage_token')) }}" class="btn-ghost min-h-0 py-2">{{ __('Cancel') }}</a>
                </div>
            </div>
        @endif
    </div>
</x-public-layout>
