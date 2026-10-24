<x-app-layout :title="__('Event types')">
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="page-title">{{ __('Event types') }}</h1>
                <p class="mt-1 muted">{{ __('Each one gets its own booking link.') }}</p>
            </div>
            <a href="{{ route('event-types.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> {{ __('New event type') }}
            </a>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl space-y-12 px-4 sm:px-6 lg:px-8">
            @if (session('status') === 'event-type-deleted')
                <div class="alert-info -mb-6" role="status"><x-icon name="check" class="mt-0.5 h-4 w-4" /> {{ __('Event type deleted.') }}</div>
            @endif

            <section aria-labelledby="personal-heading">
                @if ($teams->isNotEmpty())
                    <h2 id="personal-heading" class="section-title mb-4">{{ __('Yours') }}</h2>
                @endif

                @if ($eventTypes->isEmpty())
                    <div class="card flex flex-col items-center px-6 py-16 text-center">
                        <div class="flex h-12 w-20 items-center justify-center rounded-full bg-primary-soft text-primary-ink"><x-icon name="layers" /></div>
                        <p class="mt-4 font-semibold text-ink">{{ __('No event types yet') }}</p>
                        <p class="mt-1 muted">{{ __('Create one to get a booking link you can share.') }}</p>
                        <a href="{{ route('event-types.create') }}" class="btn-primary mt-6"><x-icon name="plus" class="h-4 w-4" /> {{ __('New event type') }}</a>
                    </div>
                @else
                    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($eventTypes as $eventType)
                            @include('event-types.partials.card', ['editUrl' => route('event-types.edit', $eventType)])
                        @endforeach
                    </div>
                @endif
            </section>

            @foreach ($teams as $team)
                @php($canManage = $team->isManagedBy(auth()->user()))

                <section aria-labelledby="team-{{ $team->id }}-heading">
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="team-{{ $team->id }}-heading" class="section-title">{{ $team->name }}</h2>
                            <p class="mt-0.5 text-sm text-ink-3">{{ __('Team event types') }}</p>
                        </div>
                        @if ($canManage)
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('teams.bookings', $team) }}" class="btn-ghost">
                                    <x-icon name="calendar" class="h-4 w-4" /> {{ __('Bookings') }}
                                </a>
                                <a href="{{ route('teams.event-types.create', $team) }}" class="btn-secondary">
                                    <x-icon name="plus" class="h-4 w-4" /> {{ __('New team event type') }}
                                </a>
                            </div>
                        @endif
                    </div>

                    @if ($team->eventTypes->isEmpty())
                        <div class="card px-6 py-8 text-center muted">
                            {{ $canManage ? __('No team event types yet. Create a round robin or collective one.') : __('No team event types yet.') }}
                        </div>
                    @else
                        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($team->eventTypes as $eventType)
                                @include('event-types.partials.card', ['editUrl' => $canManage ? route('teams.event-types.edit', [$team, $eventType]) : null])
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
