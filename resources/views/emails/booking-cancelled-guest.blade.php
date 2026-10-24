@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('Your booking was cancelled') }}</h1>
    <p style="margin:0;">
        @if ($byHost)
            {{ __(':host cancelled your :title on :date at :time (:timezone).', ['host' => $booking->host->name, 'title' => $booking->eventType->title, 'date' => $when['date'], 'time' => $when['time'], 'timezone' => $when['timezone']]) }}
        @else
            {{ __('You cancelled your :title with :host on :date at :time (:timezone).', ['host' => $booking->host->name, 'title' => $booking->eventType->title, 'date' => $when['date'], 'time' => $when['time'], 'timezone' => $when['timezone']]) }}
        @endif
    </p>

    @if ($booking->cancel_reason)
        <p style="margin:16px 0 0;color:#6b7280;">{{ __('Reason') }}</p>
        <p style="margin:0;white-space:pre-line;">{{ $booking->cancel_reason }}</p>
    @endif

    <p style="margin:16px 0 0;">{{ __('The attached file removes it from your calendar.') }}</p>

    @if ($bookAgainUrl)
        <p style="margin:20px 0 0;">
            <a href="{{ $bookAgainUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600;">{{ __('Book a new time') }}</a>
        </p>
    @endif
@endsection
