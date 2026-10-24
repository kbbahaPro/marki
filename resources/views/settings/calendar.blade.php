<x-app-layout :title="__('Calendar')">
    <x-slot name="header">
        <h1 class="page-title">
            {{ __('Calendar') }}
        </h1>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="card p-5 sm:p-8">
                <section class="max-w-xl space-y-4">
                    <header>
                        <h2 class="section-title">{{ __('Google Calendar') }}</h2>
                        <p class="mt-1 text-sm text-ink-2">{{ __('Connect your calendar so busy times are hidden from your booking pages and new bookings (with Google Meet links) are added to it.') }}</p>
                    </header>

                    @if (session('error'))
                        <div class="alert-danger" role="alert">{{ session('error') }}</div>
                    @elseif (session('status') === 'calendar-connected')
                        <div class="alert-success">{{ __('Google Calendar connected.') }}</div>
                    @elseif (session('status') === 'calendar-disconnected')
                        <div class="alert-info">{{ __('Google Calendar disconnected.') }}</div>
                    @endif

                    @if (! $configured)
                        <p class="text-sm text-warning">{{ __('Google sign-in is not configured on this server yet. An administrator needs to set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET (see DEPLOYMENT.md).') }}</p>
                    @elseif ($connection === null)
                        <form method="post" action="{{ route('settings.calendar.connect') }}">
                            @csrf
                            <x-primary-button>{{ __('Connect Google Calendar') }}</x-primary-button>
                        </form>
                    @else
                        <div class="rounded-md border border-line p-4 text-sm">
                            <div class="font-medium text-ink">{{ $connection->account_email }}</div>
                            @if ($connection->isActive())
                                <div class="mt-1 text-success">● {{ __('Connected') }}</div>
                                @if ($connection->last_synced_at)
                                    <div class="mt-1 text-ink-3">{{ __('Last checked :when', ['when' => $connection->last_synced_at->diffForHumans()]) }}</div>
                                @endif
                            @else
                                <div class="mt-1 text-danger">● {{ __('Disconnected — your booking pages show no times until you reconnect.') }}</div>
                            @endif
                        </div>

                        <div class="flex items-center gap-3">
                            @unless ($connection->isActive())
                                <form method="post" action="{{ route('settings.calendar.connect') }}">
                                    @csrf
                                    <x-primary-button>{{ __('Reconnect') }}</x-primary-button>
                                </form>
                            @endunless
                            <form method="post" action="{{ route('settings.calendar.disconnect') }}">
                                @csrf
                                @method('delete')
                                <x-secondary-button type="submit">{{ __('Disconnect') }}</x-secondary-button>
                            </form>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
