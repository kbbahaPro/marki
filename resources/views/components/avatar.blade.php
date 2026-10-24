@props(['user', 'size' => 'md', 'decorative' => false])

{{--
    A user's photo, or their initials on the accent colour when there is none.
    Pass "decorative" when the name is already written next to it, so screen readers do not hear it twice.
--}}
@php
    $sizes = [
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-11 w-11 text-sm',
        'lg' => 'h-14 w-14 text-lg',
        'xl' => 'h-24 w-24 text-3xl',
    ];
    $classes = 'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($url = $user->avatarUrl())
    {{-- Lazy by default; pass loading="eager" for photos visible on first paint. --}}
    <img src="{{ $url }}" alt="{{ $decorative ? '' : $user->name }}" decoding="async"
         {{ $attributes->merge(['class' => $classes.' bg-surface-2 object-cover', 'loading' => 'lazy']) }}>
@elseif ($decorative)
    <span {{ $attributes->merge(['class' => $classes.' bg-primary font-bold text-white']) }} aria-hidden="true">{{ $user->initials() }}</span>
@else
    <span {{ $attributes->merge(['class' => $classes.' bg-primary font-bold text-white']) }} role="img" aria-label="{{ $user->name }}">{{ $user->initials() }}</span>
@endif
