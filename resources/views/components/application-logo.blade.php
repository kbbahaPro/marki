{{-- Brand mark + wordmark. BRAND_LOGO_URL replaces both with your own image. --}}
@if (config('booking.brand.logo_url'))
    <img src="{{ config('booking.brand.logo_url') }}" alt="{{ config('app.name') }}" {{ $attributes->merge(['class' => 'h-8 w-auto']) }}>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
        <x-brand-mark class="h-8 w-auto" />
        <span class="wordmark text-2xl leading-none">{{ config('booking.brand.wordmark') ?: config('app.name') }}</span>
    </span>
@endif
