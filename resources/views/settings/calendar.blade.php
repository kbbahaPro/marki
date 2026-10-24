<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Calendar') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <section class="max-w-xl space-y-4">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('Google Calendar') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('Connect your calendar so busy times are hidden from your booking pages and new bookings (with Google Meet links) are added to it.') }}</p>
                    </header>

                    @if (session('error'))
                        <div class="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
                    @elseif (session('status') === 'calendar-connected')
                        <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">{{ __('Google Calendar connected.') }}</div>
                    @elseif (session('status') === 'calendar-disconnected')
                        <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">{{ __('Google Calendar disconnected.') }}</div>
                    @endif

                    @if (! $configured)
                        <p class="text-sm text-amber-700">{{ __('Google sign-in is not configured on this server yet. An administrator needs to set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET (see DEPLOYMENT.md).') }}</p>
                    @elseif ($connection === null)
                        <form method="post" action="{{ route('settings.calendar.connect') }}">
                            @csrf
                            <x-primary-button>{{ __('Connect Google Calendar') }}</x-primary-button>
                        </form>
                    @else
                        <div class="rounded-md border border-gray-200 p-4 text-sm">
                            <div class="font-medium text-gray-900">{{ $connection->account_email }}</div>
                            @if ($connection->isActive())
                                <div class="mt-1 text-green-700">● {{ __('Connected') }}</div>
                                @if ($connection->last_synced_at)
                                    <div class="mt-1 text-gray-500">{{ __('Last checked :when', ['when' => $connection->last_synced_at->diffForHumans()]) }}</div>
                                @endif
                            @else
                                <div class="mt-1 text-red-700">● {{ __('Disconnected — your booking pages show no times until you reconnect.') }}</div>
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
