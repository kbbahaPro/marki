@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('You are scheduled with :host', ['host' => $booking->host->name]) }}</h1>
    @if ($booking->host->headline())
        <p style="margin:-4px 0 12px;color:#6b7280;">{{ $booking->host->headline() }}</p>
    @endif
    <p style="margin:0;">{{ __('Hi :name, your booking is confirmed. The attached invitation adds it to your calendar.', ['name' => $booking->guest_name]) }}</p>

    @include('emails.partials.booking-details')

    @if ($where['url'])
        <p style="margin:0 0 20px;">
            <a href="{{ $where['url'] }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;">{{ __('Join the meeting') }}</a>
        </p>
    @endif

    @include('emails.partials.manage-links')
@endsection
