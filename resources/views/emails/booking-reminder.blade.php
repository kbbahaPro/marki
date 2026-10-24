@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('Your meeting with :host starts :when', ['host' => $booking->host->name, 'when' => $startsIn]) }}</h1>
    <p style="margin:0;">{{ __('Hi :name, this is a reminder of your upcoming booking.', ['name' => $booking->guest_name]) }}</p>

    @include('emails.partials.booking-details')

    @if ($where['url'])
        <p style="margin:0 0 20px;">
            <a href="{{ $where['url'] }}" style="display:inline-block;background:{{ $brandPrimary }};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;">{{ __('Join the meeting') }}</a>
        </p>
    @endif

    @if ($manageUrl)
        @include('emails.partials.manage-links')
    @else
        <p style="margin:0;color:#6b7280;font-size:13px;">{{ __('Need to make a change? Reply to this email to reach :host.', ['host' => $booking->host->name]) }}</p>
    @endif
@endsection
