@props(['selected' => null])

<select {{ $attributes->merge(['class' => 'input']) }}>
    @foreach (DateTimeZone::listIdentifiers() as $tz)
        <option value="{{ $tz }}" @selected($tz === $selected)>{{ str_replace('_', ' ', $tz) }}</option>
    @endforeach
</select>
