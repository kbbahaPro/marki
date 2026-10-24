@php
    use App\Enums\SyncStatus;
    use Illuminate\Support\Carbon;

    $tz = auth()->user()->timezone;
    $tabLabels = ['upcoming' => __('Upcoming'), 'past' => __('Past'), 'cancelled' => __('Cancelled')];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Things that need the host's attention --}}
            @if ($connection && ! $connection->isActive())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800" role="alert">
                    {{ __('Your Google Calendar is disconnected, so your booking pages show no available times.') }}
                    <a href="{{ route('settings.calendar') }}" class="font-medium underline">{{ __('Reconnect now') }}</a>
                </div>
            @elseif (! $connection)
                <div class="rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                    {{ __('Connect Google Calendar so your busy times are respected and bookings get Meet links.') }}
                    <a href="{{ route('settings.calendar') }}" class="font-medium underline">{{ __('Connect') }}</a>
                </div>
            @endif

            @if ($eventTypeCount === 0)
                <div class="rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                    {{ __('You have no active event types, so nobody can book you yet.') }}
                    <a href="{{ route('event-types.create') }}" class="font-medium underline">{{ __('Create one') }}</a>
                </div>
            @endif

            <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg flex flex-wrap items-center justify-between gap-3" x-data="{ copied: false }">
                <div>
                    <div class="text-sm text-gray-500">{{ __('Your booking page') }}</div>
                    <a href="{{ route('public.profile', auth()->user()->username) }}" class="font-medium text-indigo-600 hover:underline" target="_blank" rel="noopener">{{ route('public.profile', auth()->user()->username) }}</a>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-gray-600">{{ trans_choice(':count upcoming booking|:count upcoming bookings', $counts['upcoming']) }}</span>
                    <button type="button" class="text-indigo-600 hover:text-indigo-800"
                            @click="navigator.clipboard.writeText(@js(route('public.profile', auth()->user()->username))); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))"></button>
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg">
                <nav class="flex gap-6 border-b border-gray-100 px-4 sm:px-6" aria-label="{{ __('Bookings') }}">
                    @foreach ($tabLabels as $key => $label)
                        <a href="{{ route('dashboard', ['tab' => $key]) }}"
                           class="-mb-px border-b-2 py-4 text-sm font-medium {{ $tab === $key ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700' }}"
                           @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                @if ($bookings->isEmpty())
                    <p class="p-6 text-sm text-gray-500">
                        @switch($tab)
                            @case('upcoming') {{ __('No upcoming bookings. Share your booking page to get some.') }} @break
                            @case('past') {{ __('No past bookings yet.') }} @break
                            @default {{ __('No cancelled bookings.') }}
                        @endswitch
                    </p>
                @else
                    @foreach ($days as $date => $dayBookings)
                        <h3 class="bg-gray-50 px-4 sm:px-6 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ Carbon::parse($date)->translatedFormat('l, F j, Y') }}
                        </h3>
                        <ul class="divide-y divide-gray-100">
                            @foreach ($dayBookings as $booking)
                                @php($start = $booking->start_at->setTimezone($tz))
                                <li>
                                    <a href="{{ route('dashboard.bookings.show', $booking) }}" class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 sm:px-6 py-4 hover:bg-gray-50">
                                        <span class="w-28 shrink-0 text-sm font-medium text-gray-900 {{ $tab === 'cancelled' ? 'line-through text-gray-400' : '' }}">
                                            {{ $start->format('H:i') }} – {{ $booking->end_at->setTimezone($tz)->format('H:i') }}
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-gray-900">{{ $booking->guest_name }}</span>
                                            <span class="block truncate text-sm text-gray-500">{{ $booking->eventType->title }} · {{ $booking->guest_email }}</span>
                                        </span>
                                        @if ($tab === 'cancelled')
                                            <span class="text-xs text-gray-500">{{ $booking->cancelled_by === 'host' ? __('Cancelled by you') : __('Cancelled by guest') }}</span>
                                        @elseif ($booking->sync_status === SyncStatus::Failed)
                                            <span class="rounded bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('Not in calendar') }}</span>
                                        @endif
                                        @if ($booking->rescheduled_from_id && $tab !== 'cancelled')
                                            <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ __('Rescheduled') }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach

                    @if ($bookings->hasPages())
                        <div class="border-t border-gray-100 px-4 sm:px-6 py-3">{{ $bookings->links() }}</div>
                    @endif
                @endif
            </div>

            <p class="text-xs text-gray-500">{{ __('Times are shown in your time zone (:tz).', ['tz' => str_replace('_', ' ', $tz)]) }} <a href="{{ route('profile.edit') }}" class="underline">{{ __('Change') }}</a></p>
        </div>
    </div>
</x-app-layout>
