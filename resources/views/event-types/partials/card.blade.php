@php
    use App\Enums\LocationType;

    // $editUrl is null when the viewer cannot edit (team members who are not team admins).
    $path = parse_url($eventType->publicUrl(), PHP_URL_PATH);
@endphp

<article class="card relative flex flex-col p-6 transition-colors {{ $editUrl ? 'hover:border-primary/40' : '' }}" x-data="{ copied: false }">
    <div class="flex items-start justify-between gap-3">
        <span class="flex h-10 w-[4.5rem] items-center justify-center rounded-full text-sm font-bold tabular-nums {{ $eventType->is_active ? 'bg-primary-soft text-primary-ink' : 'bg-surface-2 text-ink-3' }}">
            {{ $eventType->duration_minutes }}<span class="ms-0.5 font-semibold">{{ __('min') }}</span>
        </span>
        <div class="flex flex-wrap justify-end gap-1.5">
            @if ($eventType->isTeamEvent())
                <span class="pill-primary">{{ $eventType->scheduling_type->label() }}</span>
            @endif
            @unless ($eventType->is_active)
                <span class="pill-neutral"><x-icon name="eye-off" class="h-3.5 w-3.5" /> {{ __('Off') }}</span>
            @endunless
        </div>
    </div>

    <h3 class="mt-5">
        @if ($editUrl)
            {{-- The stretched link makes the whole card clickable. --}}
            <a href="{{ $editUrl }}" class="text-lg font-semibold text-ink after:absolute after:inset-0 after:rounded-2xl hover:underline">{{ $eventType->title }}</a>
        @else
            <span class="text-lg font-semibold text-ink">{{ $eventType->title }}</span>
        @endif
    </h3>
    <p class="mt-1 flex items-center gap-1.5 text-sm text-ink-3">
        <x-icon :name="match ($eventType->location_type) { LocationType::GoogleMeet => 'video', LocationType::Phone => 'phone', LocationType::Custom => 'map-pin' }" class="h-4 w-4" />
        {{ $eventType->location_type->label() }}
    </p>

    @if ($eventType->isTeamEvent())
        <div class="mt-4 flex items-center">
            @forelse ($eventType->hosts->take(5) as $host)
                <x-avatar :user="$host" size="sm" class="-ms-2 ring-2 ring-surface first:ms-0" />
            @empty
                <span class="text-sm text-warning">{{ __('No hosts') }}</span>
            @endforelse
            @if ($eventType->hosts->count() > 5)
                <span class="ms-2 text-xs text-ink-3">+{{ $eventType->hosts->count() - 5 }}</span>
            @endif
        </div>
    @endif

    <div class="relative z-10 mt-6 flex items-center gap-2 border-t border-line pt-4">
        <span class="min-w-0 flex-1 truncate text-sm text-ink-3">{{ $path }}</span>
        <button type="button" class="btn-ghost min-h-0 px-2.5 py-1.5 text-xs"
                @click="navigator.clipboard.writeText(@js($eventType->publicUrl())); copied = true; setTimeout(() => copied = false, 1500)">
            <x-icon name="copy" class="h-3.5 w-3.5" x-show="! copied" />
            <x-icon name="check" class="h-3.5 w-3.5" x-show="copied" x-cloak />
            <span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))">{{ __('Copy link') }}</span>
        </button>
        <a href="{{ $eventType->publicUrl() }}" target="_blank" rel="noopener" class="btn-ghost min-h-0 px-2.5 py-1.5" aria-label="{{ __('Open booking page') }}"><x-icon name="external" class="h-3.5 w-3.5" /></a>
    </div>
</article>
