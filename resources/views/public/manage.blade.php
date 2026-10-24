@php
    use App\Enums\BookingStatus;

    $tz = $booking->guest_timezone;
    $start = $booking->start_at->setTimezone($tz);
    $end = $booking->end_at->setTimezone($tz);
    $canBookAgain = $booking->eventType->is_active && ! $booking->eventType->trashed();
@endphp

<x-public-layout :title="__('Your booking')">
    <div class="card mx-auto max-w-xl p-6 shadow-xl shadow-ink/[0.04] sm:p-10">
        @if (session('error'))
            <div class="mb-4 alert-danger" role="alert">{{ session('error') }}</div>
        @endif

        @switch(true)
            @case($booking->status === BookingStatus::Cancelled)
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ __('This booking is cancelled') }}</h1>
                @if (session('status') === 'cancelled')
                    <p class="mt-1 text-ink-2">{{ __('We have let :host know. A confirmation is on its way to your inbox.', ['host' => $booking->hostNames()]) }}</p>
                @endif
                @break
            @case($booking->status === BookingStatus::Rescheduled)
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ __('This booking was moved') }}</h1>
                <p class="mt-1 text-ink-2">{{ __('It was rescheduled to a new time. Please use the links in your most recent email.') }}</p>
                @break
            @case($booking->start_at->isPast())
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ __('This meeting has already started') }}</h1>
                <p class="mt-1 text-ink-2">{{ __('It can no longer be changed online. Reply to your confirmation email to reach :host.', ['host' => $booking->host->name]) }}</p>
                @break
            @default
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ __('Your booking') }}</h1>
        @endswitch

        <dl class="mt-6 space-y-3 border-t border-line pt-6 text-sm">
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-ink-3">{{ __('What') }}</dt><dd class="font-medium text-ink">{{ $booking->eventType->title }} {{ __('with') }} {{ $booking->hostNames() }}</dd></div>
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-ink-3">{{ __('When') }}</dt><dd class="text-ink {{ $booking->isChangeable() ? '' : 'line-through text-ink-3' }}">
                {{ $start->translatedFormat('l, F j, Y') }}<br>
                {{ $start->format('H:i') }} – {{ $end->format('H:i') }} <span class="text-ink-3">({{ str_replace('_', ' ', $tz) }})</span>
            </dd></div>
            <div class="flex gap-3"><dt class="w-20 shrink-0 text-ink-3">{{ __('Who') }}</dt><dd class="text-ink">{{ $booking->guest_name }}</dd></div>
        </dl>

        @if ($booking->isChangeable())
            <div class="mt-8 space-y-6">
                @if ($canBookAgain)
                    <a href="{{ route('bookings.reschedule', $token) }}" class="btn-primary">
                        {{ __('Pick a new time') }}
                    </a>
                @endif

                <form method="post" action="{{ route('bookings.cancel', $token) }}" class="space-y-3 border-t border-line pt-6"
                      onsubmit="return confirm(@js(__('Cancel this booking?')))">
                    @csrf
                    <h2 class="section-title">{{ __('Cancel this booking') }}</h2>
                    <div>
                        <x-input-label for="reason" :value="__('Reason (optional, shared with :host)', ['host' => $booking->hostNames()])" />
                        <textarea id="reason" name="reason" rows="3" maxlength="1000" class="mt-1 block w-full input">{{ old('reason') }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                    </div>
                    <x-danger-button>{{ __('Cancel booking') }}</x-danger-button>
                </form>
            </div>
        @elseif ($booking->status === BookingStatus::Cancelled && $canBookAgain)
            <a href="{{ $booking->eventType->publicUrl() }}" class="mt-8 btn-primary">
                {{ __('Book a new time') }}
            </a>
        @endif
    </div>
</x-public-layout>
