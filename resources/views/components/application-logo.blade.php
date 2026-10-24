{{-- Brand mark: the time-slot pill with a marker, the product's one motif. A logo URL in config/booking.php replaces it. --}}
@if (config('booking.brand.logo_url'))
    <img src="{{ config('booking.brand.logo_url') }}" alt="{{ config('app.name') }}" {{ $attributes->merge(['class' => 'h-8 w-auto']) }}>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
        <svg class="h-7 w-auto" viewBox="0 0 44 24" aria-hidden="true">
            <rect width="44" height="24" rx="12" class="fill-primary"/>
            <circle cx="32" cy="12" r="6" fill="white"/>
        </svg>
        <span class="text-base font-bold tracking-tight text-ink">{{ config('app.name') }}</span>
    </span>
@endif
