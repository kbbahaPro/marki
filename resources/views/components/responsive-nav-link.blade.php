@props(['active'])

<a {{ $attributes->merge(['class' => ($active ?? false)
    ? 'flex items-center gap-3 rounded-xl bg-primary-soft px-3 py-3 text-base font-semibold text-primary-ink'
    : 'flex items-center gap-3 rounded-xl px-3 py-3 text-base font-medium text-ink-2 hover:bg-surface-2 hover:text-ink']) }}
   @if ($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
