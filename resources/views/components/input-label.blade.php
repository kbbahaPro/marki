@props(['value'])

<label {{ $attributes->merge(['class' => 'label']) }}>
    {{ $value ?? $slot }}
</label>
