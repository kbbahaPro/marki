@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('Your meeting link is ready') }}</h1>
    <p style="margin:0 0 16px;">{{ __(':title with :host on :date at :time (:timezone).', ['title' => $booking->eventType->title, 'host' => $booking->hostNames(), 'date' => $when['date'], 'time' => $when['time'], 'timezone' => $when['timezone']]) }}</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $booking->meeting_url }}" style="display:inline-block;background:{{ $brandPrimary }};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;">{{ __('Join the meeting') }}</a>
    </p>
    <p style="margin:0;color:#6b7280;font-size:13px;">{{ $booking->meeting_url }}</p>
@endsection
