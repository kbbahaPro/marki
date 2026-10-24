@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;">{{ __('New booking with :guest', ['guest' => $booking->guest_name]) }}</h1>
    <p style="margin:0;">
        @if ($inGoogleCalendar)
            {{ __('It has been added to your Google Calendar.') }}
        @else
            {{ __('Use the attached file to add it to your calendar.') }}
        @endif
    </p>

    @include('emails.partials.booking-details')

    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;">
        <tr>
            <td style="padding:2px 12px 2px 0;width:120px;color:#6b7280;vertical-align:top;">{{ __('Name') }}</td>
            <td style="padding:2px 0;">{{ $booking->guest_name }}</td>
        </tr>
        <tr>
            <td style="padding:2px 12px 2px 0;color:#6b7280;vertical-align:top;">{{ __('Email') }}</td>
            <td style="padding:2px 0;">{{ $booking->guest_email }}</td>
        </tr>
        @if ($booking->guest_phone)
            <tr>
                <td style="padding:2px 12px 2px 0;color:#6b7280;vertical-align:top;">{{ __('Phone') }}</td>
                <td style="padding:2px 0;">{{ $booking->guest_phone }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:2px 12px 2px 0;color:#6b7280;vertical-align:top;">{{ __('Their time zone') }}</td>
            <td style="padding:2px 0;">{{ str_replace('_', ' ', $booking->guest_timezone) }}</td>
        </tr>
        @foreach ($booking->answers as $answer)
            <tr>
                <td style="padding:2px 12px 2px 0;color:#6b7280;vertical-align:top;">{{ $answer->label }}</td>
                <td style="padding:2px 0;white-space:pre-line;">{{ $answer->value ?? '—' }}</td>
            </tr>
        @endforeach
    </table>

    @if ($booking->guest_notes)
        <p style="margin:0 0 4px;color:#6b7280;">{{ __('Notes from :guest', ['guest' => $booking->guest_name]) }}</p>
        <p style="margin:0 0 20px;white-space:pre-line;">{{ $booking->guest_notes }}</p>
    @endif

    <p style="margin:0;color:#6b7280;font-size:13px;">{{ __('Reply to this email to reach :guest.', ['guest' => $booking->guest_name]) }}</p>
@endsection
