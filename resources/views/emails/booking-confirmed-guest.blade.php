@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('You are scheduled with :host', ['host' => $booking->host->name]) }}</h1>
    <p style="margin:0;">{{ __('Hi :name, your booking is confirmed. The attached invitation adds it to your calendar.', ['name' => $booking->guest_name]) }}</p>

    @include('emails.partials.booking-details')

    @if ($where['url'])
        <p style="margin:0 0 20px;">
            <a href="{{ $where['url'] }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600;">{{ __('Join the meeting') }}</a>
        </p>
    @endif

    @include('emails.partials.manage-links')
@endsection
