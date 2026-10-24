@props(['active'])

{{-- Active item sits in a pill, echoing the time-slot motif. --}}
<a {{ $attributes->merge(['class' => ($active ?? false)
    ? 'inline-flex items-center gap-2 whitespace-nowrap rounded-full bg-primary-soft px-3.5 py-2 text-sm font-semibold text-primary-ink'
    : 'inline-flex items-center gap-2 whitespace-nowrap rounded-full px-3.5 py-2 text-sm font-medium text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink']) }}
   @if ($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
