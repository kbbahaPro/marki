{{--
    The Marki mark: a bookmark (the time slot) holding a 3×3 dot grid (the
    calendar) with one dot marked. Colours follow the brand tokens, so it
    changes with BRAND_PRIMARY / BRAND_MARKER and with dark mode.
--}}
<svg {{ $attributes->merge(['class' => 'h-8 w-auto']) }} viewBox="16 4 68 92" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M26 6H74A8 8 0 0 1 82 14V94L50 74L18 94V14A8 8 0 0 1 26 6Z" style="fill: rgb(var(--primary))"/>
    <g fill="#fff" fill-opacity=".85">
        <circle cx="36" cy="24" r="4"/><circle cx="50" cy="24" r="4"/><circle cx="64" cy="24" r="4"/>
        <circle cx="36" cy="38" r="4"/><circle cx="50" cy="38" r="4"/>
        <circle cx="36" cy="52" r="4"/><circle cx="50" cy="52" r="4"/><circle cx="64" cy="52" r="4"/>
    </g>
    <circle cx="64" cy="38" r="7.5" style="fill: rgb(var(--marker))"/>
</svg>
