@extends('emails.layout')

@section('content')
    @if ($reassigned)
        <h1 style="margin:0 0 8px;font-size:20px;">{{ __(':guest rescheduled', ['guest' => $booking->guest_name]) }}</h1>
        <p style="margin:0;">{{ __(':title on :date at :time (:timezone) moved to a time when another host takes it, so it is no longer on your schedule.', ['title' => $booking->eventType->title, 'date' => $when['date'], 'time' => $when['time'], 'timezone' => $when['timezone']]) }}</p>
    @else
        <h1 style="margin:0 0 8px;font-size:20px;">{{ __(':guest cancelled', ['guest' => $booking->guest_name]) }}</h1>
        <p style="margin:0;">{{ __(':title on :date at :time (:timezone) is cancelled.', ['title' => $booking->eventType->title, 'date' => $when['date'], 'time' => $when['time'], 'timezone' => $when['timezone']]) }}</p>
    @endif

    @if ($booking->cancel_reason)
        <p style="margin:16px 0 0;color:#6b7280;">{{ __('Reason') }}</p>
        <p style="margin:0;white-space:pre-line;">{{ $booking->cancel_reason }}</p>
    @endif

    <p style="margin:16px 0 0;">
        @if ($removedFromGoogle)
            {{ __('It has been removed from your Google Calendar and the time is open again.') }}
        @else
            {{ __('The time is open again. Use the attached file to remove it from your calendar.') }}
        @endif
    </p>
@endsection
