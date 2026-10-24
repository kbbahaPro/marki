@php
    use App\Enums\LocationType;

    $user = auth()->user();
    $tz = $user->timezone;
    $hour = now($tz)->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 18 ? __('Good afternoon') : __('Good evening'));
    $bookingPage = route('public.profile', $user->username);
    $tabLabels = ['upcoming' => __('Upcoming'), 'past' => __('Past'), 'cancelled' => __('Cancelled')];
@endphp

<x-app-layout :title="__('Dashboard')">
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="page-title">{{ $greeting }}, {{ strtok($user->name, ' ') }}</h1>
                <p class="mt-1 muted">{{ trans_choice('You have :count upcoming booking.|You have :count upcoming bookings.', $counts['upcoming']) }}</p>
            </div>
            <a href="{{ route('event-types.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> {{ __('New event type') }}
            </a>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            {{-- Things that need attention --}}
            @if ($connection && ! $connection->isActive())
                <div class="alert-danger" role="alert">
                    <x-icon name="alert" class="mt-0.5 h-4 w-4" />
                    <p>{{ __('Your Google Calendar is disconnected, so your booking pages show no available times.') }}
                        <a href="{{ route('settings.calendar') }}" class="font-semibold underline">{{ __('Reconnect now') }}</a></p>
                </div>
            @endif

            @if ($eventTypeCount === 0)
                <div class="alert-warning">
                    <x-icon name="info" class="mt-0.5 h-4 w-4" />
                    <p>{{ __('You have no active event types, so nobody can book you yet.') }}
                        <a href="{{ route('event-types.create') }}" class="font-semibold underline">{{ __('Create one') }}</a></p>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                {{-- Bookings --}}
                <section class="card self-start overflow-hidden lg:col-span-2" aria-labelledby="bookings-heading">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <h2 id="bookings-heading" class="section-title">{{ __('Bookings') }}</h2>
                        <nav class="tabs" aria-label="{{ __('Booking filter') }}">
                            @foreach ($tabLabels as $key => $label)
                                <a href="{{ route('dashboard', ['tab' => $key]) }}" class="{{ $tab === $key ? 'tab-active' : 'tab' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                        </nav>
                    </div>

                    @if ($bookings->isEmpty())
                        <div class="flex flex-col items-center px-6 py-16 text-center">
                            <div class="flex h-12 w-20 items-center justify-center rounded-full bg-surface-2 text-ink-3">
                                <x-icon name="calendar" />
                            </div>
                            <p class="mt-4 font-semibold text-ink">
                                @switch($tab)
                                    @case('upcoming') {{ __('No upcoming bookings') }} @break
                                    @case('past') {{ __('No past bookings yet') }} @break
                                    @default {{ __('No cancelled bookings') }}
                                @endswitch
                            </p>
                            @if ($tab === 'upcoming')
                                <p class="mt-1 muted">{{ __('Share your booking page to get your first one.') }}</p>
                            @endif
                        </div>
                    @else
                        @include('dashboard.partials.booking-days')

                        @if ($bookings->hasPages())
                            <div class="border-t border-line px-5 py-3">{{ $bookings->links() }}</div>
                        @endif
                    @endif
                </section>

                <aside class="space-y-6">
                    {{-- Next meeting --}}
                    @if ($next)
                        @php($nextStart = $next->start_at->setTimezone($tz))
                        <section class="card relative overflow-hidden p-5" aria-labelledby="next-heading">
                            <div class="motif -right-16 -top-10 h-28 w-56 opacity-10" aria-hidden="true"></div>
                            <h2 id="next-heading" class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-ink-3"><span class="h-2.5 w-2.5 rounded-full bg-marker" aria-hidden="true"></span>{{ __('Next up') }}</h2>
                            <p class="mt-3 text-3xl font-bold tracking-tight text-ink tabular-nums">{{ $nextStart->format('H:i') }}</p>
                            <p class="text-sm text-ink-2">{{ $nextStart->isToday() ? __('Today') : $nextStart->translatedFormat('l, F j') }} · {{ $next->start_at->diffForHumans() }}</p>
                            <p class="mt-4 font-semibold text-ink">{{ $next->guest_name }}</p>
                            <p class="text-sm text-ink-3">{{ $next->eventType->title }}</p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                @if ($next->meeting_url)
                                    <a href="{{ $next->meeting_url }}" class="btn-primary" target="_blank" rel="noopener"><x-icon name="video" class="h-4 w-4" /> {{ __('Join') }}</a>
                                @elseif ($next->location_type === LocationType::Phone && $next->guest_phone)
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $next->guest_phone) }}" class="btn-primary"><x-icon name="phone" class="h-4 w-4" /> {{ __('Call') }}</a>
                                @endif
                                <a href="{{ route('dashboard.bookings.show', $next) }}" class="btn-secondary">{{ __('Details') }}</a>
                            </div>
                        </section>
                    @endif

                    {{-- Booking link --}}
                    <section class="card p-5" x-data="{ copied: false }" aria-labelledby="link-heading">
                        <h2 id="link-heading" class="section-title">{{ __('Your booking page') }}</h2>
                        <p class="mt-1 muted">{{ __('Share it, or a link to a single event type.') }}</p>
                        <div class="mt-4 flex items-center gap-2 rounded-full border border-line bg-surface-2/60 py-1 pe-1 ps-4">
                            <a href="{{ $bookingPage }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ preg_replace('#^https?://#', '', $bookingPage) }}</a>
                            <button type="button" class="btn-secondary min-h-0 px-3 py-1.5 text-xs"
                                    @click="navigator.clipboard.writeText(@js($bookingPage)); copied = true; setTimeout(() => copied = false, 1500)">
                                <x-icon name="copy" class="h-3.5 w-3.5" x-show="! copied" />
                                <x-icon name="check" class="h-3.5 w-3.5" x-show="copied" x-cloak />
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                            </button>
                        </div>
                    </section>

                    {{-- Calendar status --}}
                    <section class="card p-5" aria-labelledby="calendar-heading">
                        <h2 id="calendar-heading" class="section-title">{{ __('Google Calendar') }}</h2>
                        @if ($connection?->isActive())
                            <p class="mt-2 flex items-center gap-2 text-sm text-success"><x-icon name="check-circle" class="h-4 w-4" /> {{ __('Connected') }}</p>
                            <p class="mt-1 truncate text-sm text-ink-3">{{ $connection->account_email }}</p>
                        @else
                            <p class="mt-1 muted">{{ __('Connect Google Calendar so busy times are respected and bookings get Meet links.') }}</p>
                            <a href="{{ route('settings.calendar') }}" class="btn-secondary mt-4">{{ $connection ? __('Reconnect') : __('Connect') }}</a>
                        @endif
                    </section>

                    <p class="px-1 text-xs text-ink-3">
                        <x-icon name="globe" class="me-1 inline h-3.5 w-3.5 align-[-2px]" />{{ __('Times are shown in your time zone (:tz).', ['tz' => str_replace('_', ' ', $tz)]) }}
                        <a href="{{ route('profile.edit') }}" class="link">{{ __('Change') }}</a>
                    </p>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
