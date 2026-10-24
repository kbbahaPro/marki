@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('Your booking has a new time') }}</h1>
    <p style="margin:0;">
        @if ($by === 'host')
            {{ __('Hi :name, :host moved your :title to a new time. The attached invitation updates your calendar.', ['name' => $booking->guest_name, 'title' => $booking->eventType->title, 'host' => $booking->host->name]) }}
        @else
            {{ __('Hi :name, your :title with :host has moved. The attached invitation updates your calendar.', ['name' => $booking->guest_name, 'title' => $booking->eventType->title, 'host' => $booking->hostNames()]) }}
        @endif
    </p>

    <p style="margin:16px 0 0;color:#6b7280;text-decoration:line-through;">{{ $previousWhen['date'] }}, {{ $previousWhen['time'] }}</p>

    @include('emails.partials.booking-details')

    @if ($where['url'])
        <p style="margin:0 0 20px;">
            <a href="{{ $where['url'] }}" style="display:inline-block;background:{{ $brandPrimary }};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;">{{ __('Join the meeting') }}</a>
        </p>
    @endif

    @include('emails.partials.manage-links')
@endsection
