@php
    $tabLabels = ['upcoming' => __('Upcoming'), 'past' => __('Past'), 'cancelled' => __('Cancelled')];
@endphp

<x-app-layout :title="__(':team bookings', ['team' => $team->name])">
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="page-title">{{ __(':team bookings', ['team' => $team->name]) }}</h1>
                <p class="mt-1 muted">{{ __('Every booking of the team’s event types. Members see the ones they attend on their dashboard.') }}</p>
            </div>
            <a href="{{ route('event-types.index') }}#team-{{ $team->id }}-heading" class="btn-secondary">{{ __('Team event types') }}</a>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <section class="card overflow-hidden" aria-labelledby="bookings-heading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                    <h2 id="bookings-heading" class="sr-only">{{ __('Bookings') }}</h2>
                    <nav class="tabs" aria-label="{{ __('Booking filter') }}">
                        @foreach ($tabLabels as $key => $label)
                            <a href="{{ route('teams.bookings', [$team, 'tab' => $key, 'host' => $hostId]) }}" class="{{ $tab === $key ? 'tab-active' : 'tab' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>

                    <form method="get" action="{{ route('teams.bookings', $team) }}" class="flex items-center gap-2">
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <label for="host" class="text-sm text-ink-3">{{ __('Host') }}</label>
                        <select id="host" name="host" class="input min-h-0 py-1.5 text-sm" onchange="this.form.submit()">
                            <option value="">{{ __('Everyone') }}</option>
                            @foreach ($team->members as $member)
                                <option value="{{ $member->id }}" @selected($member->id === $hostId)>{{ $member->name }}</option>
                            @endforeach
                        </select>
                        <noscript><button class="btn-secondary min-h-0 py-1.5 text-sm">{{ __('Filter') }}</button></noscript>
                    </form>
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
                            <p class="mt-1 muted">{{ __('Share the team page to get the first one:') }} <a href="{{ $team->publicUrl() }}" class="link" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', $team->publicUrl()) }}</a></p>
                        @endif
                    </div>
                @else
                    @include('dashboard.partials.booking-days')

                    @if ($bookings->hasPages())
                        <div class="border-t border-line px-5 py-3">{{ $bookings->links() }}</div>
                    @endif
                @endif
            </section>

            <p class="mt-4 px-1 text-xs text-ink-3">
                <x-icon name="globe" class="me-1 inline h-3.5 w-3.5 align-[-2px]" />{{ __('Times are shown in your time zone (:tz).', ['tz' => str_replace('_', ' ', $tz)]) }}
            </p>
        </div>
    </div>
</x-app-layout>
