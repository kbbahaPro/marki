{{-- Shared <head> branding: favicons, theme colour and the configured brand colours. --}}
@php($palette = \App\Support\BrandPalette::fromConfig())

@if ($favicon = config('booking.brand.favicon_url'))
    <link rel="icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
@endif
<meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#090d18" media="(prefers-color-scheme: dark)">
<meta name="application-name" content="{{ config('app.name') }}">
<style>{!! $palette->css() !!}</style>
