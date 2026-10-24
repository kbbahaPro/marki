@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __(':guest rescheduled', ['guest' => $booking->guest_name]) }}</h1>
    <p style="margin:0;">
        @if ($inGoogleCalendar)
            {{ __('Your Google Calendar has been updated.') }}
        @else
            {{ __('Use the attached file to update your calendar.') }}
        @endif
    </p>

    <p style="margin:16px 0 0;color:#6b7280;text-decoration:line-through;">{{ $previousWhen['date'] }}, {{ $previousWhen['time'] }}</p>

    @include('emails.partials.booking-details')

    <p style="margin:0;color:#6b7280;font-size:13px;">{{ __('Reply to this email to reach :guest.', ['guest' => $booking->guest_name]) }}</p>
@endsection
