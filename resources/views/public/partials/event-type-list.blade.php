@php
    use App\Enums\LocationType;
@endphp

{{-- Bookable event types as large links. Expects $eventTypes. --}}
<ul class="mt-10 space-y-3">
    @foreach ($eventTypes as $eventType)
        <li>
            <a href="{{ $eventType->publicUrl() }}"
               class="card group flex items-center gap-5 p-5 transition-colors hover:border-primary/40 sm:p-6">
                <span class="flex h-12 w-20 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary-ink tabular-nums">
                    {{ $eventType->duration_minutes }}<span class="ms-0.5 font-semibold">{{ __('min') }}</span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-ink">{{ $eventType->title }}</span>
                    <span class="mt-0.5 flex items-center gap-1.5 text-sm text-ink-3">
                        <x-icon :name="match ($eventType->location_type) { LocationType::GoogleMeet => 'video', LocationType::Phone => 'phone', LocationType::Custom => 'map-pin' }" class="h-4 w-4" />
                        {{ $eventType->location_type->label() }}
                    </span>
                </span>
                <x-icon name="arrow-right" class="h-5 w-5 text-ink-3 transition-transform group-hover:translate-x-1 group-hover:text-primary-ink" />
            </a>
        </li>
    @endforeach
</ul>
