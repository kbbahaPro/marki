<x-public-layout :title="$team->name">
    <div class="mx-auto max-w-2xl">
        <div class="text-center">
            {{-- Overlapping member photos, like a team in one frame --}}
            @if ($team->members->isNotEmpty())
                <div class="flex justify-center -space-x-3">
                    @foreach ($team->members->take(6) as $member)
                        <x-avatar :user="$member" size="lg" class="ring-4 ring-canvas" loading="eager" />
                    @endforeach
                    @if ($team->members->count() > 6)
                        <span class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-surface-2 text-sm font-semibold text-ink-2 ring-4 ring-canvas">+{{ $team->members->count() - 6 }}</span>
                    @endif
                </div>
            @endif

            <h1 class="mt-5 font-display text-3xl font-bold tracking-tight text-ink">{{ $team->name }}</h1>
            @if ($team->description)
                <p class="mt-2 whitespace-pre-line text-ink-2">{{ $team->description }}</p>
            @endif
        </div>

        @if ($eventTypes->isEmpty())
            <div class="card mt-10 p-8 text-center text-ink-2">{{ __('No meetings are available to book with this team right now.') }}</div>
        @else
            @include('public.partials.event-type-list')
        @endif
    </div>
</x-public-layout>
