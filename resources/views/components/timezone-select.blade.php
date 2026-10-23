@props(['selected' => null])

<select {{ $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>
    @foreach (DateTimeZone::listIdentifiers() as $tz)
        <option value="{{ $tz }}" @selected($tz === $selected)>{{ str_replace('_', ' ', $tz) }}</option>
    @endforeach
</select>
